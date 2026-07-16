<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\ConvertStaticToSelfRector;
use Rector\Config\RectorConfig;
use Rector\Php53\Rector\Ternary\TernaryToElvisRector;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php80\Rector\Class_\StringableForToStringRector;
use Rector\Php80\Rector\Switch_\ChangeSwitchToMatchRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnNeverTypeRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/php-classes',
        __DIR__.'/php-config',
        __DIR__.'/php-migrations',
        __DIR__.'/event-handlers',
        __DIR__.'/site-root',
        __DIR__.'/site-tasks',
        __DIR__.'/console-commands',
        __DIR__.'/data-exporters',
        __DIR__.'/dwoo-plugins',
    ])
    // some legacy classes (Sencha tooling, spreadsheet writers) are large
    // enough to blow the default 120s parallel child timeout
    ->withParallel(timeoutSeconds: 600, jobSize: 8)
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        earlyReturn: true,
        privatization: true,

        // TYPE_DECLARATION is deliberately NOT enabled here. Unlike php-core
        // (a controlled compat layer with few overrides), skeleton-v3 is the
        // heavily-subclassed *site base*: ActiveRecord, SenchaApp, Media,
        // Token, and PasswordAuthenticator are extended by every downstream
        // product skeleton (Slate, slate-cbl, scienceleadership, b21, ...) and
        // by ~60 leaf sites. Their extension API -- validate(), __classLoaded(),
        // userCan{Create,Read,Update,Delete}Record(), getAvailableActions(),
        // __toString(), __get() -- is overridden *without* return types across
        // the fleet. Inferring native return types / typed properties on the
        // parents (as Rector's type set does) makes each untyped override a
        // fatal "Declaration must be compatible" error at class load. A review
        // of three consumer repos alone surfaced 34 such overrides, and the
        // inference was not even self-consistent within this repo (23 in-repo
        // parents typed while their overrides were skipped). Contravariant
        // param types would be safe, but the net gain there is two methods --
        // not worth carrying the return/property inference that cannot be
        // proven safe against the whole fleet from here. Revisit per-class once
        // the products adopt matching signatures.
        typeDeclarations: false,
    )
    ->withSkip([
        // Adding declare(strict_types=1) flips argument/return coercion
        // semantics across legacy classes and web-facing scripts; too risky for
        // code exercised by every downstream product skeleton.
        SafeDeclareStrictTypesRector::class,

        // Late static binding (static::) is the primary extension mechanism of
        // the Emergence class model; never rewrite it to self:: mechanically.
        ConvertStaticToSelfRector::class,

        // Drops call arguments Rector believes are surplus, but the Emergence
        // model dispatches via late static binding: several call sites pass
        // extra args to static::checkBrowseAccess()/static::_cn() that resolve
        // to *subclass* overrides accepting those args. Removing them against
        // the base signature would silently break downstream overrides.
        RemoveExtraParametersRector::class,

        // switch->match flips loose (==) to strict (===) comparison and turns a
        // silent no-match fall-through into a thrown UnhandledMatchError. Too
        // behavior-sensitive to apply mechanically across legacy request
        // routing and field-type dispatch consumed by every product skeleton.
        ChangeSwitchToMatchRector::class,

        // Reaches into the type set from the PHP-version set: adds `implements
        // \Stringable` and a `: string` return to __toString(). ActiveRecord
        // and its subclasses' __toString() are overridden untyped downstream --
        // same fatal-covariance hazard as the rest of the (disabled) type
        // inference, so hold it here too.
        StringableForToStringRector::class,

        // Also leaks in from the PHP-version set: `: never` is a hard
        // inheritance constraint. throwError()/respond()/calculateDelta() are
        // overridable request-handler hooks; a subclass that returns normally
        // would fatal. Held for the same reason as the disabled return-type
        // inference.
        ReturnNeverTypeRector::class,

        // Constructor promotion on the legacy classes renames ctor params
        // (breaking any downstream named-argument calls) and silently types
        // properties like Authenticator::$_session; the property/API change is
        // not worth it on base classes the whole fleet instantiates. (No load
        // fatal -- constructors are LSP-exempt -- but still an API break.)
        ClassPropertyAssignToConstructorPromotionRector::class,

        // Would rewrite `$a ? $a : $b` to the short ternary `$a ?: $b`, but the
        // phpstan-strict-rules gate in this same toolchain forbids short
        // ternary (disallowShortTernary). Don't emit code our own gate rejects.
        TernaryToElvisRector::class,

        // Scoped skip: Media::getFileInfo() uses is_callable([File::class,
        // 'getFileInfoResource']) as a *method-existence* probe for an optional
        // kernel method. Rewriting it to a first-class callable
        // (File::getFileInfoResource(...)) both fatals when the method is
        // absent and is non-idempotent against the reverted source, so hold the
        // rule on this one file. It stays active everywhere else.
        ArrayToFirstClassCallableRector::class => [
            __DIR__.'/php-classes/Media.class.php',
        ],
    ]);
