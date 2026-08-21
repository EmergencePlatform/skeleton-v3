<?php

namespace Emergence\Mailer;

// comma-separated domains this server's Postmark sender signatures cover,
// e.g. "slabeeber.org"; unset = no From rewriting
if ($domains = getenv('POSTMARK_VERIFIED_FROM_DOMAINS')) {
    PostmarkMailer::$verifiedFromDomains = array_map('trim', explode(',', $domains));
}
