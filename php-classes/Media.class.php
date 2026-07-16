<?php

use Emergence\People\Person;
use Emergence\Site\Storage;

class Media extends ActiveRecord
{
    public static $useCache = true;
    public static $singularNoun = 'media item';
    public static $pluralNoun = 'media items';

    // support subclassing
    public static $rootClass = self::class;
    public static $defaultClass = self::class;
    public static $subClasses = [self::class, 'PhotoMedia', 'AudioMedia', 'VideoMedia', 'PDFMedia'];
    public static $collectionRoute = '/media';

    // get rid of these??
    public static $Namespaces = [];
    public static $Types = [];


    public static $tableName = 'media';

    public static $fields = [
        'ContextClass' => [
            'type' => 'string'
            ,'notnull' => false
        ]
        ,'ContextID' => [
            'type' => 'integer'
            ,'notnull' => false
        ]
        ,'MIMEType' => 'string'
        ,'Width' => [
            'type' => 'integer'
            ,'unsigned' => true
            ,'notnull' => false
        ]
        ,'Height' => [
            'type' => 'integer'
            ,'unsigned' => true
            ,'notnull' => false
        ]
        ,'Duration' => [
            'type' => 'float'
            ,'unsigned' => true
            ,'notnull' => false
        ]
        ,'Caption' => [
            'type' => 'string'
            ,'notnull' => false
        ]
    ];

    public static $relationships = [
        'Creator' => [
            'type' => 'one-one'
            ,'class' => Person::class
            ,'local' => 'CreatorID'
        ]
        ,'Context' => [
            'type' => 'context-parent'
        ]
    ];

    public static $searchConditions = [
        'Caption' => [
            'qualifiers' => ['any','caption']
            ,'points' => 2
            ,'sql' => 'Caption LIKE "%%%s%%"'
        ]
        ,'CaptionLike' => [
            'qualifiers' => ['caption-like']
            ,'points' => 2
            ,'sql' => 'Caption LIKE "%s"'
        ]
        ,'CaptionNot' => [
            'qualifiers' => ['caption-not']
            ,'points' => 2
            ,'sql' => 'Caption NOT LIKE "%%%s%%"'
        ]
        ,'CaptionNotLike' => [
            'qualifiers' => ['caption-not-like']
            ,'points' => 2
            ,'sql' => 'Caption NOT LIKE "%s"'
        ]
    ];

    public static $webPathFormat = '/media/open/%u'; // 1=mediaID
    public static $thumbnailRequestFormat = '/thumbnail/%1$u/%2$ux%3$u%4$s'; // 1=media_id 2=width 3=height 4=fill_color
    public static $blankThumbnailRequestFormat = '/thumbnail/%1$s/%2$ux%3$u%4$s'; // 1=class 2=width 3=height 4=fill_color
    public static $thumbnailJPEGCompression = 90;
    public static $thumbnailPNGCompression = 9;
    public static $defaultFilenameFormat = 'default.%s.jpg';
    public static $newDirectoryPermissions = 0775;
    public static $newFilePermissions = 0664;
    public static $useFaceDetection = true;
    public static $faceDetectionTimeLimit = 10;

    public static $mimeHandlers = [];

    public static $mimeRewrites = [
        'image/photoshop'               => 'application/psd'
        ,'image/x-photoshop'            => 'application/psd'
        ,'image/psd'                    => 'application/psd'
        ,'application/photoshop'        => 'application/psd'
        ,'image/vnd.adobe.photoshop'    => 'application/psd'
    ];


    // privates
    protected $_webPath;
    protected $_filesystemPath;
    protected $_mediaInfo;

    public static function __classLoaded()
    {
        parent::__classLoaded();

        // load subclasses
        foreach (static::$subClasses as $subClass) {
            Site::loadClass($subClass);
        }
    }


    // magic methods
    public function getValue($name)
    {
        switch ($name) {
            case 'Data':
            case 'SummaryData':
            case 'JsonTranslation':
                return [
                    'ID' => $this->ID
                    ,'Class' => $this->Class
                    ,'ContextClass' => $this->ContextClass
                    ,'ContextID' => $this->ContextID
                    ,'MIMEType' => $this->MIMEType
                    ,'Width' => $this->Width
                    ,'Height' => $this->Height
                    ,'Duration' => $this->Duration
                ];

            case 'Filename':
                return $this->getFilename();

            case 'ThumbnailMIMEType':
                return $this->MIMEType;

            case 'Extension':
                throw new MediaTypeException('Unable to find extension for mime-type: '.$this->MIMEType);

            case 'WebPath':

                if (!isset($this->_webPath)) {
                    $this->_webPath = sprintf(
                        static::$webPathFormat,
                        $this->ID
                    );
                }

                return $this->_webPath;


            case 'FilesystemPath':
                return $this->getFilesystemPath();


            case 'BlankPath':

                return static::getBlankPath($this->ContextClass);


            default:
                return parent::getValue($name);
        }
    }


    // public methods
    public static function getBlankThumbnailRequest($class, $width, $height, $fillColor = null)
    {
        return sprintf(
            static::$blankThumbnailRequestFormat,
            $class,
            $width,
            $height,
            (isset($fillColor) ? 'x'.$fillColor : '')
        );
    }

    /**
     * @deprecated
     */
    public function getThumbnailRequest($width, $height = null, $fillColor = null, $cropped = false)
    {
        return sprintf(
            static::$thumbnailRequestFormat,
            $this->ID,
            $width,
            $height ?: $width,
            (is_string($fillColor) ? 'x'.$fillColor : '')
        ).($cropped ? '/cropped' : '');
    }

    /**
     * Wrap around original getThumbnailRequest to override new interface getThumbnailRequest
     */
    public function getImageUrl($maxWidth = null, $maxHeight = null, array $options = [])
    {
        return $this->getThumbnailRequest(
            $maxWidth ?: $maxHeight,
            $maxHeight ?: $maxWidth,
            empty($options['fillColor']) ? null : $options['fillColor'],
            !empty($options['cropped'])
        );
    }

    public function getImage(array $options = [])
    {
        switch ($this->MIMEType) {
            case 'application/psd':
            case 'image/tiff':

                //Converts PSD to PNG temporarily on the real file system.
                $tempFile = tempnam('/tmp', 'media_convert');
                exec("convert -density 100 ".escapeshellarg((string) $this->getLocalPath())."[0] -flatten $tempFile.png");

                return imagecreatefrompng("$tempFile.png");

            case 'application/pdf':

                return PDFMedia::getImage();

            case 'application/postscript':

                return imagecreatefromstring(shell_exec("gs -r150 -dEPSCrop -dNOPAUSE -dBATCH -sDEVICE=png48 -sOutputFile=- -q ".escapeshellarg((string) $this->getLocalPath())));

            default:
                if ($this->isPhantom) {
                    $sourceFile = $this->BlankPath;

                    if (!$fileData = @file_get_contents($sourceFile)) {
                        throw new Exception('Could not load media source: '.$sourceFile);
                    }
                } else {
                    $sourceFile = $this->getStoragePath();

                    try {
                        $fileData = static::getFilesystem()->read($sourceFile);
                    } catch (\League\Flysystem\FileNotFoundException) {
                        $fileData = false;
                    }

                    if ($fileData === false) {
                        throw new Exception('Could not load media source: '.$sourceFile);
                    }
                }

                $image = imagecreatefromstring($fileData);

                if ($this->MIMEType == 'image/jpeg' && ($exifData = static::readExifData($fileData)) && !empty($exifData['Orientation'])) {
                    switch ($exifData['Orientation']) {
                        case 1: // nothing
                            break;
                        case 2: // horizontal flip
                            imageflip($image, IMG_FLIP_HORIZONTAL); // TODO: need PHP 5.3 compat method
                            break;
                        case 3: // 180 rotate left
                            $image = imagerotate($image, 180, null);
                            break;
                        case 4: // vertical flip
                            imageflip($image, IMG_FLIP_VERTICAL); // TODO: need PHP 5.3 compat method
                            break;
                        case 5: // vertical flip + 90 rotate right
                            imageflip($image, IMG_FLIP_VERTICAL); // TODO: need PHP 5.3 compat method
                            $image = imagerotate($image, -90, null);
                            break;
                        case 6: // 90 rotate right
                            $image = imagerotate($image, -90, null);
                            break;
                        case 7: // horizontal flip + 90 rotate right
                            imageflip($image, IMG_FLIP_HORIZONTAL); // TODO: need PHP 5.3 compat method
                            $image = imagerotate($image, -90, null);
                            break;
                        case 8: // 90 rotate left
                            $image = imagerotate($image, 90, null);
                            break;
                    }
                }

                return $image;
        }
    }

    /**
     * Ensure a thumbnail exists in the media storage bucket, generating and
     * storing it if needed, and return its bucket-relative storage path
     */
    public function ensureThumbnail($maxWidth, $maxHeight, $fillColor = false, $cropped = false)
    {
        // init thumbnail path
        $thumbFormat = sprintf('%ux%u', $maxWidth, $maxHeight);

        if ($fillColor) {
            $thumbFormat .= 'x'.strtoupper((string) $fillColor);
        }

        if ($cropped) {
            $thumbFormat .= '.cropped';
        }

        $storagePath = $thumbFormat.'/'.$this->Filename;
        $fs = static::getFilesystem();

        // look for cached thumbnail
        if (!$fs->has($storagePath)) {
            // render to a local temporary file, then write into the bucket
            $tempPath = tempnam(sys_get_temp_dir(), 'media-thumb-');

            try {
                $this->createThumbnailImage($tempPath, $maxWidth, $maxHeight, $fillColor, $cropped);

                $tempStream = fopen($tempPath, 'rb');
                $fs->putStream($storagePath, $tempStream);

                if (is_resource($tempStream)) {
                    fclose($tempStream);
                }
            } finally {
                @unlink($tempPath);
            }
        }

        return $storagePath;
    }

    /**
     * Ensure a thumbnail exists and return a local filesystem path to it,
     * materializing a temporary copy when the media bucket is remote.
     *
     * Kept for backwards compatibility; prefer ensureThumbnail() plus
     * Media::getFilesystem() streaming for new code.
     */
    public function getThumbnail($maxWidth, $maxHeight, $fillColor = false, $cropped = false)
    {
        $storagePath = $this->ensureThumbnail($maxWidth, $maxHeight, $fillColor, $cropped);

        if (!Storage::isRemote('media')) {
            return Storage::getLocalStorageRoot().'/media/'.$storagePath;
        }

        return static::downloadToTemp($storagePath);
    }

    public function createThumbnailImage($thumbPath, $maxWidth, $maxHeight, $fillColor = false, $cropped = false)
    {
        $thumbWidth = $maxWidth;
        $thumbHeight = $maxHeight;

        if ($cropped && extension_loaded('imagick') && ($localSourcePath = $this->getLocalPath())) {
            $originalTimeLimit = ini_get('max_execution_time');

            // check for existing facedetect job
            $cacheKey = "facedetect:{$thumbPath}";
            $faceDetectTime = Cache::fetch($cacheKey);

            // a parallel or dead worker is already working on this thumb
            if ($faceDetectTime) {
                // wait for existing job to finish or timeout
                while (time() - $faceDetectTime < static::$faceDetectionTimeLimit) {
                    sleep(1);
                }

                // other worker succeeded, we're done
                if (file_exists($thumbPath)) {
                    return true;
                }

                // disable face detection because it already failed for this thumb
                static::$useFaceDetection = false;
            }

            if (static::$useFaceDetection && extension_loaded('facedetect')) {
                Cache::store($cacheKey, time());
                set_time_limit(static::$faceDetectionTimeLimit);

                $cropper = new CropFace($localSourcePath);
            } else {
                $cropper = new stojg\crop\CropEntropy($localSourcePath);
            }

            $croppedImage = $cropper->resizeAndCrop($thumbWidth, $thumbHeight);

            $croppedImage->writeimage($thumbPath);

            set_time_limit($originalTimeLimit);
            Cache::delete($cacheKey);
        } else {
            // load source image
            if (!$srcImage = $this->getImage()) {
                throw new OutOfBoundsException("{$this->MIMEType} source not available as an image");
            }

            $srcWidth = imagesx($srcImage);
            $srcHeight = imagesy($srcImage);

            // calculate
            if ($srcWidth && $srcHeight) {
                $widthRatio = ($srcWidth > $maxWidth) ? ($maxWidth / $srcWidth) : 1;
                $heightRatio = ($srcHeight > $maxHeight) ? ($maxHeight / $srcHeight) : 1;

                // crop width/height to scale size if fill disabled
                $ratio = $cropped ? max($widthRatio, $heightRatio) : min($widthRatio, $heightRatio);

                $scaledWidth = round($srcWidth * $ratio);
                $scaledHeight = round($srcHeight * $ratio);
            } else {
                $scaledWidth = $maxWidth;
                $scaledHeight = $maxHeight;
            }

            if (!$fillColor && !$cropped) {
                $thumbWidth = $scaledWidth;
                $thumbHeight = $scaledHeight;
            }

            // create thumbnail images
            $image = imagecreatetruecolor($thumbWidth, $thumbHeight);

            // paint fill color
            if ($fillColor) {
                // extract decimal values from hex triplet
                $fillColor = sscanf($fillColor, '%2x%2x%2x');

                // convert to color index
                $fillColor = imagecolorallocate($image, $fillColor[0], $fillColor[1], $fillColor[2]);

                // fill background
                imagefill($image, 0, 0, $fillColor);
            } elseif (($this->MIMEType == 'image/gif') || ($this->MIMEType == 'image/png')) {
                $trans_index = imagecolortransparent($srcImage);

                // check if there is a specific transparent color
                if ($trans_index >= 0 && $trans_index < imagecolorstotal($srcImage)) {
                    $trans_color = imagecolorsforindex($srcImage, $trans_index);

                    // allocate in thumbnail
                    $trans_index = imagecolorallocate($image, $trans_color['red'], $trans_color['green'], $trans_color['blue']);

                    // fill background
                    imagefill($image, 0, 0, $trans_index);
                    imagecolortransparent($image, $trans_index);
                } elseif ($this->MIMEType == 'image/png') {
                    imagealphablending($image, false);
                    $trans_color = imagecolorallocatealpha($image, 0, 0, 0, 127);
                    imagefill($image, 0, 0, $trans_color);
                    imagesavealpha($image, true);
                }

                /*
                            $trans_index = imagecolorallocate($image, 218, 0, 245);
                            ImageColorTransparent($image, $background); // make the new temp image all transparent
                            imagealphablending($image, false); // turn off the alpha blending to keep the alpha channel
                */
            }

            // resize photo to thumbnail
            if ($cropped) {
                imagecopyresampled(
                    $image,
                    $srcImage,
                    ($thumbWidth - $scaledWidth) / 2,
                    ($thumbHeight - $scaledHeight) / 2,
                    0,
                    0,
                    $scaledWidth,
                    $scaledHeight,
                    $srcWidth,
                    $srcHeight
                );
            } else {
                imagecopyresampled(
                    $image,
                    $srcImage,
                    round(($thumbWidth - $scaledWidth) / 2),
                    round(($thumbHeight - $scaledHeight) / 2),
                    0,
                    0,
                    $scaledWidth,
                    $scaledHeight,
                    $srcWidth,
                    $srcHeight
                );
            }

            // save thumbnail to disk
            switch ($this->ThumbnailMIMEType) {
                case 'image/gif':
                    imagegif($image, $thumbPath);
                    break;

                case 'image/jpeg':
                    imagejpeg($image, $thumbPath, static::$thumbnailJPEGCompression);
                    break;

                case 'image/png':
                    imagepng($image, $thumbPath, static::$thumbnailPNGCompression);
                    break;

                default:
                    throw new Exception('Unhandled thumbnail format');
            }
        }

        chmod($thumbPath, static::$newFilePermissions);
        return true;
    }

    /*
    public function delete()
    {
        // remove file
        @unlink($this->FilesystemPath);

        // delete record
        return $this->deleteRecord();
    }
    */


    // static methods
    public static function createFromUpload($uploadedFile, $fieldValues = [])
    {
        // handle recieving a field array from $_FILES
        if (is_array($uploadedFile)) {
            if (isset($uploadedFile['error']) && $uploadedFile['error'] != ERR_UPLOAD_OK) {
                return null;
            }

            if (!empty($uploadedFile['name']) && empty($fieldValues['Caption'])) {
                $fieldValues['Caption'] = preg_replace('/\.[^.]+$/', '', $uploadedFile['name']);
            }

            $uploadedFile = $uploadedFile['tmp_name'];
        }

        // sanity check
        if (!is_uploaded_file($uploadedFile)) {
            throw new Exception('Supplied file is not a valid upload');
        }

        return static::createFromFile($uploadedFile, $fieldValues);
    }

    public static function createFromFile($file, $fieldValues = [])
    {
        try {
            // handle url input
            if (filter_var($file, FILTER_VALIDATE_URL)) {
                $tempName = tempnam('/tmp', 'remote_media');
                copy($file, $tempName);
                $file = $tempName;
            }

            // analyze file
            $mediaInfo = static::analyzeFile($file);

            // create media object
            $Media = $mediaInfo['className']::create($fieldValues);

            // init media
            $Media->initializeFromAnalysis($mediaInfo);

            // save media
            $Media->save();

            // write file
            $Media->writeFile($file);

            return $Media;
        } catch (Exception $e) {
            \Emergence\Logger::general_warning('Caught exception while processing media upload, aborting upload and returning null', [
                'exceptionClass' => $e::class
                ,'exceptionMessage' => $e->getMessage()
                ,'exceptionCode' => $e->getCode()
                ,'recordData' => $Media ? $Media->getData() : null
                ,'mediaInfo' => $mediaInfo
            ]);

            // remove photo record
            if ($Media) {
                $Media->destroy();
            }

            throw $e;
        }
    }

    public function initializeFromAnalysis($mediaInfo)
    {
        $this->MIMEType = $mediaInfo['mimeType'];
        $this->Width = $mediaInfo['width'];
        $this->Height = $mediaInfo['height'];
        $this->Duration = $mediaInfo['duration'];
    }


    public static function analyzeFile($filename)
    {
        // DO NOT CALL FROM decendent's override, parent calls child

        // check file
        if (!is_readable($filename)) {
            throw new Exception('Unable to read media file for analysis: "'.$filename.'"');
        }

        // get mime type
        $mimeType = File::getMIMEType($filename);

        // dig deeper if only generic mimetype returned
        if ($mimeType == 'application/octet-stream') {
            // prefer kernel method if available
            if (is_callable([File::class, 'getFileInfoResource'])) {
                $finfo = File::getFileInfoResource(FILEINFO_NONE);
            } else {
                $finfo = finfo_open(FILEINFO_NONE);
            }

            if (!$finfo || !($fileInfo = finfo_file($finfo, $filename))) {
                throw new Exception('Unable to load media file info');
            }

            finfo_close($finfo);

            // detect EPS
            if (preg_match('/^DOS EPS/i', $fileInfo)) {
                $mimeType = 'application/postscript';
            }
        } elseif (array_key_exists($mimeType, static::$mimeRewrites)) {
            $mimeType = static::$mimeRewrites[$mimeType];
        }

        // condense


        // compile mime data
        $mediaInfo = [
            'mimeType' => $mimeType
        ];

        // determine handler
        $staticClass = static::class;

        if (!isset(static::$mimeHandlers[$mediaInfo['mimeType']]) || $staticClass != 'Media') {
            // MICS::dump(static::$mimeHandlers, 'MIME Handlers');
            // throw new MediaTypeException('No class registered for mime type "' . $mediaInfo['mimeType'] . '"');

            $mediaInfo['className'] = $staticClass;
        } else {
            $mediaInfo['className'] = static::$mimeHandlers[$mediaInfo['mimeType']];

            // call registered type's analyzer
            $mediaInfo = call_user_func([$mediaInfo['className'], 'analyzeFile'], $filename, $mediaInfo);
        }

        return $mediaInfo;
    }

    public static function getBlankPath($contextClass)
    {
        $path = ['site-root','img',sprintf(static::$defaultFilenameFormat, $contextClass)];

        if ($node = Site::resolvePath($path)) {
            return $node->RealPath;
        }

        return null;
    }

    public static function getBlank($contextClass)
    {
        // get image info
        $sourcePath = static::getBlankPath($contextClass);
        $sourceInfo = @getimagesize($sourcePath);

        if (!$sourceInfo) {
            throw new OutOfBoundsException("Unable to load blank image for context '$contextClass'");
        }

        // get mime type
        $mimeType = image_type_to_mime_type($sourceInfo[2]);

        // determine type
        if (!isset(static::$mimeHandlers[$mimeType])) {
            throw new MediaTypeException('No class registered for mime type "'.$mimeType.'"');
        }

        $className = static::$mimeHandlers[$mimeType];


        $blankMedia = new $className();
        $blankMedia->ContextClass = $contextClass;
        $blankMedia->MIMEType = $mimeType;
        $blankMedia->Width = $sourceInfo[0];
        $blankMedia->Height = $sourceInfo[1];

        return $blankMedia;
    }

    public static function getSupportedTypes()
    {
        return array_unique(array_merge(array_keys(static::$mimeHandlers), array_keys(static::$mimeRewrites)));
    }

    /**
     * Get the Flysystem filesystem backing the media storage bucket
     *
     * @return \League\Flysystem\FilesystemInterface
     */
    public static function getFilesystem()
    {
        return Storage::getFilesystem('media');
    }

    /**
     * Get the bucket-relative storage path for a variant of this media,
     * for use with static::getFilesystem()
     */
    public function getStoragePath($variant = 'original', $filename = null)
    {
        if ($this->isPhantom) {
            return null;
        }

        return $variant.'/'.($filename ?: $this->getFilename($variant));
    }

    /**
     * Get a local filesystem path to a variant of this media, materializing
     * a temporary copy when the media bucket is remote (e.g. object storage).
     * Returns null if the media is phantom or the file cannot be read.
     */
    public function getLocalPath($variant = 'original')
    {
        if ($this->isPhantom) {
            return null;
        }

        if (!Storage::isRemote('media')) {
            return $this->getFilesystemPath($variant);
        }

        return static::downloadToTemp($this->getStoragePath($variant));
    }

    /**
     * Copy a path in the media storage bucket to a local temporary file
     * and return its path, or null if the source cannot be read
     */
    protected static function downloadToTemp($storagePath)
    {
        try {
            $remoteStream = static::getFilesystem()->readStream($storagePath);
        } catch (\League\Flysystem\FileNotFoundException) {
            return null;
        }

        if ($remoteStream === false) {
            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'media-local-');
        $localStream = fopen($tempPath, 'wb');
        stream_copy_to_stream($remoteStream, $localStream);
        fclose($localStream);

        if (is_resource($remoteStream)) {
            fclose($remoteStream);
        }

        return $tempPath;
    }

    /**
     * Read EXIF data from an in-memory image string
     *
     * @return array|false
     */
    protected static function readExifData($fileData)
    {
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, (string) $fileData);
        rewind($stream);

        $exifData = @exif_read_data($stream);

        fclose($stream);

        return $exifData;
    }

    /**
     * Get the legacy local filesystem path for a variant of this media.
     *
     * Only meaningful when the media bucket is locally stored; prefer
     * getStoragePath() with static::getFilesystem(), or getLocalPath()
     * when a real local file is required (e.g. shelling out).
     */
    public function getFilesystemPath($variant = 'original', $filename = null)
    {
        if ($this->isPhantom) {
            return null;
        }

        return Storage::getLocalStorageRoot().'/media/'.$variant.'/'.($filename ?: $this->getFilename($variant));
    }

    public function getFilename($variant = 'original')
    {
        if ($this->isPhantom) {
            return 'default.'.$this->Extension;
        }

        return $this->ID.'.'.$this->Extension;
    }

    public function getMIMEType($variant = 'original')
    {
        return $this->MIMEType;
    }

    public function writeFile($sourceFile)
    {
        $sourceStream = fopen($sourceFile, 'rb');

        if ($sourceStream === false) {
            throw new \Exception('Failed to open source file for media write');
        }

        // write source file to storage bucket
        if (!static::getFilesystem()->putStream($this->getStoragePath(), $sourceStream)) {
            throw new \Exception('Failed to write source file to media storage');
        }

        if (is_resource($sourceStream)) {
            fclose($sourceStream);
        }

        // remove source file (legacy contract: source was moved into place)
        unlink($sourceFile);
    }

    public function isVariantAvailable($variant)
    {
        return false;
    }
}
