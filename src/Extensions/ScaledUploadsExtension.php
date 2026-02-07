<?php

namespace Sunnysideup\ScaledUploads\Extensions;

use SilverStripe\Core\Extension;
use Sunnysideup\ScaledUploads\Api\Resizer;

/**
 * Class \Sunnysideup\ScaledUploads\Extensions\ScaledUploadsExtension
 *
 * @property ScaledUploadsExtension $owner
 */
class ScaledUploadsExtension extends Extension
{
    /**
     * Post data manipulation
     *
     * @param $file File Silverstripe file object
     */
    public function onAfterLoadIntoFile($file)
    {
        // return if not an image
        if (! $file->getIsImage()) {
            return;
        }

        Resizer::create()
            // ->setUseWebp(false)
            // ->setKeepOriginal(true)
            // ->setMaxWidth(100)
            ->runFromDbFile($file);
    }
}
