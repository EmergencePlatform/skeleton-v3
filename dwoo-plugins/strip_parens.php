<?php

function Dwoo_Plugin_strip_parens(Dwoo_Core $dwoo, $input)
{
    return trim((string) preg_replace('/\([^)]*\)/', '', (string) $input));
}
