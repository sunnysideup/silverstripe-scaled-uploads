<?php

namespace Sunnysideup\ScaledUploads\Extensions;

use Sunnysideup\ScaledUploads\Api\Resizer;
use SilverStripe\Core\Extension;

/**
 * Class \Sunnysideup\ScaledUploads\Extensions\ScaledUploadsExtension
 *
 * @property Upload|ScaledUploadsExtension $owner
 */
class ScaledUploadsExtension extends Extension
{
    /**
     * Post data manipulation
     *
     * @param $file File Silverstripe file object
     *
     * @return null
     */
    public function onAfterLoadIntoFile($file)
    {
        // return if not an image
        if (!$file->getIsImage()) {
            return;
        }

        $file = Resizer::create()
            // ->setUseWebp(false)
            // ->setKeepOriginal(true)
            // ->setMaxWidth(100)
            ->runFromDbFile($file);
    }


}
