<?php

namespace emteknetnz\Deprecator;

use SilverStripe\Dev\BuildTask;

class DeprecationTask extends BuildTask
{
    protected $title = 'DeprecationTask';

    protected $description = 'Used to assist deprecations in Silverstripe CMS 4';

    public function run($request)
    {
        $vendorDirs = [
            // BASE_PATH . '/vendor/dnadesign',
            BASE_PATH . '/vendor/silverstripe',
            // BASE_PATH . '/vendor/symbiote',
            // BASE_PATH . '/vendor/bringyourownideas',
            // BASE_PATH . '/vendor/colymba',
            // BASE_PATH . '/vendor/cwp',
            // BASE_PATH . '/vendor/tractorcow',
        ];
        foreach ($vendorDirs as $vendorDir) {
            if (!file_exists($vendorDir)) {
                continue;
            }
            foreach (scandir($vendorDir) as $subdir) {
                if (in_array($subdir, ['.', '..'])) {
                    continue;
                }
                $dir = "$vendorDir/$subdir";
                if ($dir != '/var/www/vendor/silverstripe/framework') {
                    continue;
                }
                foreach (['src', 'code', 'tests', 'thirdparty'] as $d) {
                    $subdir = "$dir/$d";
                    if (file_exists($subdir)) {
                        $this->update($subdir);
                    }
                }
            }
        }
    }

    public function update(string $subdir)
    {
        echo "$subdir\n";
    }
}
