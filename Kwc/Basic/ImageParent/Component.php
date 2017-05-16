<?php
class Kwc_Basic_ImageParent_Component extends Kwc_Abstract
    implements Kwf_Media_Output_IsValidInterface
{
    const CONTENT_WIDTH = 'contentWidth';
    public static function getSettings($param = null)
    {
        $ret = parent::getSettings($param);
        $ret['dimension'] = array('width'=>100, 'height'=>100, 'cover' => false);
        $ret['imgCssClass'] = '';
        $ret['lazyLoadOutOfViewport'] = true; // Set to false to load image also when not in view
        $ret['pdfMaxWidth'] = 0;
        $ret['pdfMaxDpi'] = 150;
        $ret['defineWidth'] = false;
        $ret['outputImgTag'] = true;
        return $ret;
    }

    public static function validateSettings($settings, $componentClass)
    {
        parent::validateSettings($settings, $componentClass);
        if (isset($settings['dimensions'])) {
            throw new Kwf_Exception("Don't set dimensions, use dimension for a single one");
        }
    }

    public function getTemplateVars(Kwf_Component_Renderer_Abstract $renderer)
    {
        $ret = parent::getTemplateVars($renderer);
        $ret['imgCssClass'] = $this->_getSetting('imgCssClass');
        $ret['style'] = '';
        $ret['containerClass'] = $this->_getBemClass("container");
        $ret['image'] = $this->getData();
        $imageComponent = $this->_getImageComponent();
        if ($imageComponent) {
            $ret['altText'] = $imageComponent->getAltText();

            $imageData = $this->getImageData();
            $ret = array_merge($ret,
                Kwf_Media_Output_Component::getResponsiveImageVars($this->getImageDimensions(), $imageData['file'])
            );
            $ret['style'] .= 'max-width:'.$ret['width'].'px;';
            if ($this->_getSetting('defineWidth')) $ret['style'] .= 'width:'.$ret['width'].'px;';
            if ($ret['width'] > 100) $ret['containerClass'] .= ' kwfUp-webResponsiveImgLoading';
        }
        $ret['baseUrl'] = $this->getBaseImageUrl();
        $ret['defineWidth'] = $this->_getSetting('defineWidth');
        $ret['lazyLoadOutOfViewport'] = $this->_getSetting('lazyLoadOutOfViewport');
        $ret['outputImgTag'] = $this->_getSetting('outputImgTag');

        if (!$this->_getSetting('lazyLoadOutOfViewport')) $ret['containerClass'] .= ' kwfUp-loadImmediately';

        if (!$renderer instanceof Kwf_Component_Renderer_Mail) { //TODO this check is a hack
            $ret['template'] = Kwf_Component_Renderer_Twig_TemplateLocator::getComponentTemplate('Kwc_Abstract_Image_Component');
        }
        return $ret;
    }

    public function getImageDimensions()
    {
        $dimension = $this->_getSetting('dimension');
        if ($dimension['width'] === self::CONTENT_WIDTH) {
            $dimension['width'] = $this->getContentWidth();
        }
        $parentDimension = $this->_getImageComponent()->getConfiguredImageDimensions();
        if (isset($parentDimension['crop'])) $dimension['crop'] = $parentDimension['crop'];
        $data = $this->getImageData();
        return Kwf_Media_Image::calculateScaleDimensions($data['file'], $dimension);
    }

    /**
     * Returns the source image component using for image data
     *
     * returns parent by default.
     *
     * WARNING: when overriding this you also have to write custom Events to take care of clearing caches
     */
    protected function _getImageComponent()
    {
        return $this->getData()->parent->getComponent();
    }

    public function getImageData()
    {
        if (!$this->_getImageComponent()) return null;
        return $this->_getImageComponent()->getImageData();
    }

    public function getImageUrl()
    {
        $baseUrl = $this->getBaseImageUrl();
        if ($baseUrl) {
            $dimensions = $this->getImageDimensions();
            $imageData = $this->getImageData();
            $width = Kwf_Media_Image::getResponsiveWidthStep($dimensions['width'],
                    Kwf_Media_Image::getResponsiveWidthSteps($dimensions, $imageData['file']));
            return str_replace('{width}', $width, $baseUrl);
        }
        return null;
    }

    public function getBaseType()
    {
        $type = Kwf_Media::DONT_HASH_TYPE_PREFIX.'{width}';
        $type .= '-'.substr(md5(json_encode($this->_getSetting('dimension'))), 0, 6);
        return $type;
    }

    public function getBaseImageUrl()
    {
        $data = $this->getImageData();
        if ($data) {
            $id = $this->getData()->componentId;
            $ret = Kwf_Media::getUrl($this->getData()->componentClass, $id, $this->getBaseType(), $data['filename']);
            $ev = new Kwf_Component_Event_CreateMediaUrl($this->getData()->componentClass, $this->getData(), $ret);
            Kwf_Events_Dispatcher::fireEvent($ev);
            return $ev->url;
        }
        return null;
    }

    public static function isValidMediaOutput($id, $type, $className)
    {
        return Kwf_Media_Output_Component::isValidImage($id, $type, $className);
    }

    public static function getMediaOutput($id, $type, $className)
    {
        $component = Kwf_Component_Data_Root::getInstance()->getComponentById($id, array('ignoreVisible' => true));
        if (!$component) return null;

        $data = $component->getComponent()->getImageData();
        if (!$data) {
            return null;
        }
        $dimension = $component->getComponent()->getImageDimensions();

        return Kwf_Media_Output_Component::getMediaOutputForDimension($data, $dimension, $type);
    }

    public final function getImageDataOrEmptyImageData()
    {
        return $this->_getImageComponent()->getImageDataOrEmptyImageData();
    }

    private function _getAbsoluteUrl($url)
    {
        if ($url && substr($url, 0, 1) == '/' && substr($url, 0, 2) != '//') { //can already be absolute, due to Event_CreateMediaUrl (eg. varnish cache)
            $domain = $this->getData()->getDomain();
            $protocol = Kwf_Util_Https::domainSupportsHttps($domain) ? 'https' : 'http';
            $url = "$protocol://$domain$url";
        }
        return $url;
    }

    private function _getImageUrl($width)
    {
        return str_replace('{width}', $width, $this->getBaseImageUrl());
    }

    public function getMaxResolutionImageUrl()
    {
        $data = $imageData = $this->getImageData();
        if ($data) {
            $s = $this->getImageDimensions();
            $widths = Kwf_Media_Image::getResponsiveWidthSteps($s, $imageData['dimensions']);
            return $this->_getImageUrl(end($widths));
        }
        return null;
    }

    public function getMaxResolutionAbsoluteImageUrl()
    {
        return $this->_getAbsoluteUrl($this->getMaxResolutionImageUrl());
    }

    public function getAbsoluteImageUrl()
    {
        return $this->_getAbsoluteUrl($this->getImageUrl());
    }
}
