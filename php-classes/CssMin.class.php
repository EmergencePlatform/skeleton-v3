<?php

/* Super-simple CSS minifier adapted from */

class CssMin
{
    public static function minify($css)
    {
        $css = preg_replace('#\s+#', ' ', (string) $css);
        $css = preg_replace('#/\*.*?\*/#s', '', (string) $css);
        $css = str_replace('; ', ';', $css);
        $css = str_replace(': ', ':', $css);
        $css = str_replace(' {', '{', $css);
        $css = str_replace('{ ', '{', $css);
        $css = str_replace(', ', ',', $css);
        $css = str_replace('} ', '}', $css);
        $css = str_replace(';}', '}', $css);

        return trim($css);
    }
}