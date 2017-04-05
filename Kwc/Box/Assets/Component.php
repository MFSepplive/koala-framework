<?php
class Kwc_Box_Assets_Component extends Kwc_Abstract
{
    public static function getSettings($param = null)
    {
        $ret = parent::getSettings($param);
        $ret['flags']['hasHeaderIncludeCode'] = true;
        $ret['flags']['hasInjectIntoRenderedHtml'] = true;
        return $ret;
    }

    public function getIncludeCode()
    {
        return $this->getData();
    }

    public function getTemplateVars(Kwf_Component_Renderer_Abstract $renderer)
    {
        $ret = parent::getTemplateVars($renderer);
        $ret['language'] = $this->getData()->getLanguage();
        $ret['subroot'] = $this->getData()->getSubroot();
        $ret['assetsPackages'] = array('Frontend');

        $packageNames = array();
        $page = $this->getData()->getPage();
        if (Kwc_Abstract::getFlag($page->componentClass, 'assetsPackage')) {
            $packageName = Kwc_Abstract::getFlag($page->componentClass, 'assetsPackage');
            if ($packageName && !in_array($packageName, $packageNames)) {
                $packageNames[] = $packageName;
            }
        }
        foreach ($page->getRecursiveChildComponents(array('flags'=>array('assetsPackage'=>true), 'page'=>false)) as $d) {
            $packageName = Kwc_Abstract::getFlag($d->componentClass, 'assetsPackage');
            if ($packageName && !in_array($packageName, $packageNames)) {
                $packageNames[] = $packageName;
            }
        }
        $d = $page;
        while ($d) {
            if (Kwc_Abstract::getFlag($d->componentClass, 'assetsPackage')) {
                $packageName = Kwc_Abstract::getFlag($d->componentClass, 'assetsPackage');
                if ($packageName && !in_array($packageName, $packageNames)) {
                    $packageNames[] = $packageName;
                }
            }
            $d = $d->parent;
        }
        foreach ($packageNames as $packageName) {
            if ($packageName != 'Default') {
                $ret['assetsPackages'][] = 'ComponentPackage'.$packageName;
            }
        }
        return $ret;
    }

    /**
     * @deprecated
     */
    protected final function _getSection()
    {
    }

    public function injectIntoRenderedHtml($html)
    {
        $startPos = strpos($html, '<!-- assets -->');
        $endPos = strpos($html, '<!-- /assets -->')+16;
        $html = substr($html, 0, $startPos)
                .$this->getData()->render()
                .substr($html, $endPos);
        return $html;
    }
}
