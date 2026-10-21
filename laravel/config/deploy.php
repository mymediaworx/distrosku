<?php

// Self-deploy (public_html/deploy.php). Only the PUBLIC Ed25519 key lives here; the private key and the HMAC secret never ship.
// deploy.php reads this literal default with a regex (it does not boot Laravel). Override with DEPLOY_PUBLIC_KEY in .env.
return [
    'public_key' => env('DEPLOY_PUBLIC_KEY', 'gHqnkN78Wl+pkvP3rpxJtcq3hKKjWffWZz+FaLa6I/M='),
];
