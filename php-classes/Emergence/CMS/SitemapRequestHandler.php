<?php

namespace Emergence\CMS;

// Generated XML sitemap for indexable CMS content (published Page + BlogPost),
// so search engines discover the full corpus and re-fetch only changed URLs.
// /sitemap            -> sitemap index (chunk list)
// /sitemap/blog/<n>   -> nth chunk of published blog posts
// /sitemap/pages/<n>  -> nth chunk of published pages
// Lean by design: selects only Handle + Modified (never the content clob) so a
// full-corpus render stays cheap. See specs/behaviors/search-indexing.md.
class SitemapRequestHandler extends \RequestHandler
{
    public static $chunkSize = 10000; // well under the 50k-URL per-file limit

    // route segment => concrete content class (its $collectionRoute builds URLs)
    public static $contentClasses = [
        'pages' => Page::class,
        'blog'  => BlogPost::class,
    ];

    public static function handleRequest()
    {
        header('Content-Type: application/xml; charset=utf-8');

        $segment = static::shiftPath();

        // a known content-class segment => that class's chunk; anything else
        // (bare /sitemap, trailing slash, or a stray path) => the index
        if (isset(static::$contentClasses[$segment])) {
            $chunk = max(1, (int)(static::shiftPath() ?: 1));
            return static::respondChunk($segment, static::$contentClasses[$segment], $chunk);
        }

        return static::respondIndex();
    }

    protected static function base()
    {
        // SITE_PRIMARY_HOSTNAME is authoritative; getConfig('primary_hostname')
        // derives from HTTP_HOST on the current runtime (see knowledgebase #28)
        return 'https://'.(getenv('SITE_PRIMARY_HOSTNAME') ?: \Site::getConfig('primary_hostname'));
    }

    protected static function publishedCount($class)
    {
        return (int)\DB::oneValue(
            'SELECT COUNT(*) FROM `%s` WHERE Class = "%s" AND Status = "Published" AND Handle IS NOT NULL',
            [$class::$tableName, \DB::escape($class)]
        );
    }

    protected static function respondIndex()
    {
        $base = static::base();

        echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
        echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";

        foreach (static::$contentClasses as $segment => $class) {
            $chunks = max(1, (int)ceil(static::publishedCount($class) / static::$chunkSize));
            for ($i = 1; $i <= $chunks; $i++) {
                echo '  <sitemap><loc>', htmlspecialchars($base.'/sitemap/'.$segment.'/'.$i, ENT_XML1), '</loc></sitemap>', "\n";
            }
        }

        echo '</sitemapindex>', "\n";
        exit();
    }

    protected static function respondChunk($segment, $class, $chunk)
    {
        $base = static::base();
        $route = $class::$collectionRoute;
        $offset = ($chunk - 1) * static::$chunkSize;

        $rows = \DB::allRecords(
            'SELECT Handle, Modified FROM `%s` WHERE Class = "%s" AND Status = "Published" AND Handle IS NOT NULL ORDER BY ID LIMIT %u, %u',
            [$class::$tableName, \DB::escape($class), $offset, static::$chunkSize]
        );

        echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";

        foreach ($rows as $row) {
            echo '  <url><loc>', htmlspecialchars($base.$route.'/'.$row['Handle'], ENT_XML1), '</loc>';
            if (!empty($row['Modified'])) {
                echo '<lastmod>', gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$row['Modified'])), '</lastmod>';
            }
            echo '</url>', "\n";
        }

        echo '</urlset>', "\n";
        exit();
    }
}
