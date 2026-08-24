// ***********************************************
// This example commands.js shows you how to
// create various custom commands and overwrite
// existing commands.
//
// For more comprehensive examples of custom
// commands please read more here:
// https://on.cypress.io/custom-commands
// ***********************************************
//
//
// -- This is a parent command --
// Cypress.Commands.add("login", (email, password) => { ... })
//
//
// -- This is a child command --
// Cypress.Commands.add("drag", { prevSubject: 'element'}, (subject, options) => { ... })
//
//
// -- This is a dual command --
// Cypress.Commands.add("dismiss", { prevSubject: 'optional'}, (subject, options) => { ... })
//
//
// -- This is will overwrite an existing command --
// Cypress.Commands.overwrite("visit", (originalFn, url, options) => { ... })

// from https://github.com/javieraviles/cypress-upload-file-post-form
Cypress.Commands.add('upload_file', (fileName, fileType = ' ', selector) => {
    cy.get(selector).then(subject => {
        cy.fixture(fileName, 'base64')
            .then(Cypress.Blob.base64StringToBlob)
            .then(blob => {
                const el = subject[0]
                const testFile = new File([blob], fileName, { type: fileType })
                const dataTransfer = new DataTransfer()
                dataTransfer.items.add(testFile)
                el.files = dataTransfer.files
            })
    })
});

// Login command
Cypress.Commands.add('loginAs', (user, password) => {
    if (!user) {
        user = Cypress.env('TEST_USER') || 'user';
    }

    if (!password) {
        password = Cypress.env('TEST_PASSWORD') || user;
    }

    cy.log(`Logging into ${user}`);

    cy.request({
        method: 'POST',
        url: '/login/?format=json',
        form: true,
        body: {
            '_LOGIN[username]': user,
            '_LOGIN[password]': password,
            '_LOGIN[returnMethod]': 'POST'
        }
    });
});

// Drop and load database in one step
Cypress.Commands.add('resetDatabase', () => {
    cy.dropDatabase();
    cy.loadDatabase();
});

// Two site-execution environments are supported:
//
// - SITE_CONTAINER: a PHP-8 live-runtime site container (bundled MySQL,
//   /opt/emergence/tools/console-run.php). Fixture SQL is projected on the
//   HOST via hologit from SITE_REPO (the site holospace checkout) and piped
//   into the container; MyISAM DDL is rewritten to InnoDB to match the
//   runtime's disabled-MyISAM MySQL.
// - STUDIO_CONTAINER (legacy): a Habitat studio; everything runs through
//   `hab pkg exec` inside it via _buildHabExec below.

const _siteDb = () => Cypress.env('SITE_DB') || 'emergence-site';
const _siteRepo = () => Cypress.env('SITE_REPO') || '.';
const _siteMysql = () =>
    `docker exec -i ${Cypress.env('SITE_CONTAINER')} mysql --socket=/run/mysqld/mysqld.sock -uroot`;

// Drops the entire database
Cypress.Commands.add('dropDatabase', () => {
    const sql = `DROP DATABASE IF EXISTS \`${_siteDb()}\`; CREATE DATABASE \`${_siteDb()}\` CHARACTER SET utf8mb4;`;

    if (Cypress.env('SITE_CONTAINER')) {
        cy.exec(`echo '${sql}' | ${_siteMysql()}`);
        return;
    }

    cy.exec(`echo '${sql}' | ${_buildHabExec('core/mysql', 'mysql', '-u root -h 127.0.0.1')}`);
});

// Reload the original data fixtures
let cachedFixturesTreeHash;

Cypress.Commands.add('loadDatabase', () => {
    if (cachedFixturesTreeHash) {
        cy.log('Using cached fixtures tree', cachedFixturesTreeHash);
        return _loadFixturesTree(cachedFixturesTreeHash);
    }

    const projectCmd = Cypress.env('SITE_CONTAINER')
        ? `cd '${_siteRepo()}' && git holo project fixtures --working`
        : _buildHabExec('jarvus/hologit', 'git', `holo project fixtures --working`);

    cy.exec(projectCmd, { timeout: 300000 })
        .then(({ stdout }) => {
            const treeHash = stdout.trim().split('\n').pop();

            if (!treeHash) {
                throw new Error('unable to compute tree hash for fixtures data');
            }

            cachedFixturesTreeHash = treeHash;

            _loadFixturesTree(treeHash);
        });
});

// Ext command getter
Cypress.Commands.add('withExt', () => {
    cy.window().then((win) => {
        const Ext = win.Ext;
        return {
            Ext: win.Ext,
            extQuerySelector: query => Ext.ComponentQuery.query(query)[0],
            extQuerySelectorAll: query => Ext.ComponentQuery.query(query)
        };
    });
});

Cypress.Commands.add('extGet', { prevSubject: 'optional' }, (subject, query, options={ all: false, component: false } ) => {
    let allResults, result;

    const log = Cypress.log({
        autoEnd: false,
        name: 'extGet',
        message: `${query} [${options.component?'component':'element'}]`,
        consoleProps: () => ({
            Scope: subject,
            Options: options,
            Yielded: result,
            'All Results': allResults
        })
    });

    const extGet = (subject, query) => cy.wrap(new Cypress.Promise((resolve, reject) => {
        cy.window({ log: false }).then(win => {
            if (typeof subject != 'undefined') {
                expect(subject).to.be.an('object').and.have.property('query');
                allResults = subject.query(query);
            } else {
                allResults = win.Ext.ComponentQuery.query(query);
            }

            // map to elements
            if (!options.component) {
                allResults = allResults.map(component => component && component.el && component.el.dom)
            }

            // pick one or multiple
            result = options.all ? allResults : allResults[0] || null;

            // finish
            resolve(result);
        });
    }), { log: false });

    const resolve = () => {
        return extGet(subject, query)
            .then(result => {
                return cy.verifyUpcomingAssertions(result, options, { onRetry: resolve })
                    .then(result => {
                        if (result) {
                            log.set({
                                $el: result && result.el ? result.el.dom : result
                            });
                            log.snapshot();
                            log.end();
                        }
                    });
            });
    }

    return resolve();
});

// private method
function _buildHabExec(pkg, pkgCmd, pkgArgs) {
    const studioContainer = Cypress.env('STUDIO_CONTAINER') || null;
    const studioSSH = Cypress.env('STUDIO_SSH') || null;

    let cmd = `hab pkg exec ${pkg} -- ${pkgCmd} ${pkgArgs||''}`;

    if (studioContainer) {
        cmd = `docker exec -i ${studioContainer} ${cmd}`;

        if (studioSSH) {
            cmd = `ssh ${studioSSH} '${cmd.replace(/'/g, `'"'"'`)}'`
        }
    }

    return cmd;
}

function _loadFixturesTree(treeHash) {
    if (Cypress.env('SITE_CONTAINER')) {
        const siteContainer = Cypress.env('SITE_CONTAINER');

        cy.exec(`cd '${_siteRepo()}' && (
            echo "SET autocommit=0;"
            echo "SET unique_checks=0;"
            echo "SET foreign_key_checks=0;"
            for fixture_file in $(git ls-tree -r --name-only ${treeHash}); do
                git cat-file -p "${treeHash}:\${fixture_file}"
            done
            echo "COMMIT;"
        ) | sed 's/ENGINE=MyISAM/ENGINE=InnoDB/g' | ${_siteMysql()} ${_siteDb()}`, { timeout: 120000 });

        cy.exec(
            `docker exec -e SITE_HANDLE=${_siteDb()} ${siteContainer} php /opt/emergence/tools/console-run.php migrations:execute --all`,
            { timeout: 300000 }
        );
        return;
    }

    cy.exec(_buildHabExec('emergence/php-runtime', 'bash', `-c '
        (
            echo "SET autocommit=0;"
            echo "SET unique_checks=0;"
            echo "SET foreign_key_checks=0;"
            for fixture_file in $(git ls-tree -r --name-only ${treeHash}); do
                git cat-file -p "${treeHash}:\${fixture_file}"
            done
            echo "COMMIT;"
        ) | mysql emergence-site
    '`));

    cy.exec(_buildHabExec('emergence/php-runtime', 'emergence-console-run', `migrations:execute --all`));
}
