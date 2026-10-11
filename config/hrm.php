<?php

return [
    'identity' => ['password' => true, 'mfa' => false, 'google' => false, 'microsoft' => false, 'saml' => false, 'oidc' => false],
    'modules' => ['work', 'notifications'],
    'data_scopes' => ['self', 'tenant'],
    // Department/team scopes need an employee membership model in a later phase.
];
