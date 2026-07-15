<?php

function Dwoo_Plugin_versioned_url(Dwoo_Core $dwoo, $path, $source = 'site-root', $includeHost = false, $absolute = false)
{
    $trimmedPath = ltrim((string) $path, '/');

    if ($source == 'site-root') {
        $url = Site::getVersionedRootUrl($trimmedPath);

        if ($includeHost || $absolute) {
            return Emergence\Util\Url::buildAbsolute($url);
        }

        return $url;
    }
    return $path;
}