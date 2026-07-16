<?php

function Dwoo_Plugin_linkify(Dwoo_Core $dwoo, $text, $twitter = false)
{
    // Optionally, look for and linkify Twitter usernames, hashtags, etc using Matt Sanford's official-ish php class
    if ($twitter) {
        $Tweetify = new Twitter_Autolink();
        return $Tweetify->autolink($text);
    }

    return preg_replace('/([\w]+:\/\/[\w\-?&;#~=\.\/\@+]+[\w\/])/i', '<a target="_blank" rel="nofollow" href="$1">$1</a>', (string) $text);
}
