<?php

class CropFace extends \stojg\crop\CropFace
{
    protected function getFaceListFromClassifier($classifier)
    {
        return face_detect($this->imagePath, Site::resolvePath('php-classes/stojg/crop'.$classifier)->RealPath);
    }
}