<?php

/**
 * Every source package: its slug (omnilex/<slug>, github.com/glitchr-omnisense/omnilex-<slug>),
 * its tests' namespace and its factory class.
 */
return [
    'legifrance' => ['Omnilex\\Legifrance\\Tests\\', 'Omnilex\\Legifrance\\LegifranceSourceFactory'],
    'judilibre' => ['Omnilex\\Judilibre\\Tests\\', 'Omnilex\\Judilibre\\JudilibreSourceFactory'],
    'eurlex' => ['Omnilex\\Eurlex\\Tests\\', 'Omnilex\\Eurlex\\EurlexSourceFactory'],
    'justice-administrative' => ['Omnilex\\JusticeAdministrative\\Tests\\', 'Omnilex\\JusticeAdministrative\\JusticeAdministrativeSourceFactory'],
];
