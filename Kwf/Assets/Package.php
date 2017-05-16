<?php
class Kwf_Assets_Package
    implements Kwf_Assets_Interface_UrlResolvable, Serializable
{
    protected $_providerList;
    protected $_dependencyName;
    protected $_dependency;

    private $_cacheFilteredUniqueDependencies;
    private $_chunkedCssCache;
    private $_depContentsCache;
    private $_packageContentsCache = array();

    public function __construct(Kwf_Assets_ProviderList_Abstract $providerList, $dependencyName)
    {
        $this->_providerList = $providerList;
        if (!is_string($dependencyName)) {
            throw new Kwf_Exception("dependencyName needs to be a string");
        }
        $this->_dependencyName = $dependencyName;
    }

    public function __toString()
    {
        return get_class($this);
    }

    public function getDependencyName()
    {
        return $this->_dependencyName;
    }

    /**
     * @return Kwf_Assets_Dependency_Abstract
     */
    public function getDependency()
    {
        if (!isset($this->_dependency)) {
            $d = $this->_providerList->findDependency($this->_dependencyName);
            if (!$d) {
                throw new Kwf_Exception("Could not resolve dependency '$this->_dependencyName'");
            }
            $this->_dependency = $d;
        }
        return $this->_dependency;
    }

    /**
     * @return Kwf_Assets_ProviderList_Abstract
     */
    public function getProviderList()
    {
        return $this->_providerList;
    }

    //default impl doesn't cache, overriden in Package_Default
    protected function _getCacheId($mimeType)
    {
        return null;
    }

    public function getFilteredUniqueDependencies($mimeType)
    {
        return $this->_getFilteredUniqueDependencies($mimeType);
    }

    protected function _getFilteredUniqueDependencies($mimeType)
    {
        if (!isset($this->_cacheFilteredUniqueDependencies[$mimeType])) {
            $this->_cacheFilteredUniqueDependencies[$mimeType] = $this->getDependency()->getFilteredUniqueDependencies($mimeType);
        }
        return $this->_cacheFilteredUniqueDependencies[$mimeType];
    }

    /**
     * Get built contents of a package, to be used by eg. mails
     */
    public function getBuildContents($mimeType, $language)
    {
        if ($mimeType == 'text/javascript') $ext = 'js';
        else if ($mimeType == 'text/javascript; defer') $ext = 'defer.js';
        else if ($mimeType == 'text/css') $ext = 'css';

        $cacheId = Kwf_Assets_Dispatcher::getInstance()->getCacheIdByPackage($this, $ext, $language);
        $ret = Kwf_Assets_BuildCache::getInstance()->load($cacheId);
        if ($ret === false || $ret === 'outdated') {
            if ($ret === 'outdated' && Kwf_Config::getValue('assets.lazyBuild') == 'outdated') {
                Kwf_Assets_BuildCache::getInstance()->building = true;
            } else if (Kwf_Config::getValue('assets.lazyBuild') !== true) {
                if (Kwf_Exception_Abstract::isDebug()) {
                    //proper error message on development server
                    throw new Kwf_Exception("Building assets is disabled (assets.lazyBuild). Please include package in build.");
                } else {
                    throw new Kwf_Exception_NotFound();
                }
            }
            $ret = $this->getPackageContents($mimeType, $language)->getFileContents();
            Kwf_Assets_BuildCache::getInstance()->building = false;
        } else {
            $ret = $ret['contents'];
        }
        return $ret;
    }

    public function warmupDependencyCaches($dep, $mimeType, $progress = null)
    {
        if (!$dep->getIncludeInPackage()) return;

        $cacheId = $dep->getCacheId();
        if ($cacheId) {
            $cacheId = 'filtered-'.$cacheId;
        }

        $ret = false;
        if ($cacheId) {
            $ret = Kwf_Assets_ContentsCache::getInstance()->load($cacheId);
        }
        if ($ret === false) {

            if (!isset($this->_depContentsCache[$dep->getIdentifier()])) {
                $ret = $dep->getContentsPacked();
                if (!$ret) {
                    throw new Kwf_Exception("Dependency '$dep' didn't return contents");
                }
                foreach ($dep->getFilters() as $filter) {
                    if ($progress) $progress->update(null, $dep->__toString().' '.str_replace('Kwf_Assets_Dependency_Filter_', '', get_class($filter)));
                    $ret = $filter->filter($ret, $dep);
                }
                foreach ($this->getProviderList()->getFilters() as $filter) {
                    if ($filter->getExecuteFor() == Kwf_Assets_Filter_Abstract::EXECUTE_FOR_DEPENDENCY
                        && $filter->getMimeType() == $dep->getMimeType()
                    ) {
                        if ($progress) $progress->update(null, $dep->__toString().' '.str_replace('Kwf_Assets_Filter_', '', get_class($filter)));
                        $ret = $filter->filter($ret, $dep);
                    }
                }
                $this->_depContentsCache[$dep->getIdentifier()] = $ret;
            } else {
                $ret = $this->_depContentsCache[$dep->getIdentifier()];
            }


            if ($cacheId) {
                Kwf_Assets_ContentsCache::getInstance()->save($ret, $cacheId);
            }
        }
        return $ret;
    }

    private function _getFilteredDependencyContents($dep, $mimeType)
    {
        return $this->warmupDependencyCaches($dep, $mimeType);
    }

    protected function _getCommonJsDeps($i, &$data)
    {
        $ret = array();
        foreach ($i->getDependencies(Kwf_Assets_Dependency_Abstract::DEPENDENCY_TYPE_COMMONJS) as $depName=>$dep) {
            $ret[$depName] = $dep->__toString();
            if (!isset($data[$dep->__toString()])) {
                $c = $this->_getFilteredDependencyContents($dep, 'text/javascript');
                $data[$dep->__toString()] = array(
                    'id' => $dep->__toString(),
                    'source' => $c,
                    'deps' => array(),
                    'entry' => false
                );
                $data[$dep->__toString()]['deps'] = $this->_getCommonJsDeps($dep, $data);
            }
        }
        return $ret;
    }

    protected function _getCommonJsData($mimeType)
    {
        $commonJsData = array();
        $commonJsDeps = array();
        if ($mimeType == 'text/javascript' || $mimeType == 'text/javascript; defer') {
            foreach ($this->_getFilteredUniqueDependencies($mimeType) as $i) {
                if ($i->getIncludeInPackage()) {
                    if (($mimeType == 'text/javascript' || $mimeType == 'text/javascript; defer') && $i->isCommonJsEntry()) {
                        $c = $this->_getFilteredDependencyContents($i, $mimeType);
                        $commonJsDeps = $this->_getCommonJsDeps($i, $commonJsData);
                        $commonJsData[$i->__toString()] = array(
                            'id' => $i->__toString(),
                            'source' => $c,
                            'deps' => $commonJsDeps,
                            'entry' => true
                        );
                    }
                }
            }
        }
        if ($commonJsData) {
            if ($mimeType == 'text/javascript; defer') {
                //in defer.js don't include deps that are already loaded in non-defer
                foreach ($this->_getFilteredUniqueDependencies('text/javascript') as $i) {
                    $data = array();
                    $commonJsDeps = $this->_getCommonJsDeps($i, $data);
                    foreach (array_keys($data) as $key) {
                        if (isset($commonJsData[$key])) {
                            unset($commonJsData[$key]);
                        }
                    }
                }
            }
        }
        return $commonJsData;
    }

    private function _buildPackageContents($mimeType)
    {
        foreach ($this->_providerList->getProviders() as $provider) {
            $provider->initialize();
        }

        $packageMap = Kwf_SourceMaps_SourceMap::createEmptyMap('');
        if ($mimeType == 'text/css') {
            $packageMap->setMimeType('text/css');
        } else {
            $packageMap->setMimeType('text/javascript');
        }

        $trlData = array();


        // ***** commonjs
        $commonJsData = $this->_getCommonJsData($mimeType);
        if ($commonJsData) {
            foreach ($commonJsData as &$i) {
                $data = $i['source']->getMapContentsData(false);
                if (isset($data->{'_x_org_koala-framework_trlData'})) {
                    $trlData = array_merge($trlData, $data->{'_x_org_koala-framework_trlData'});
                }
                $data->sourcesContent = $data->sources; //browser-pack needs sourcesContent, else it would ignore input source map. This is fake obviously and we'll drop it anyway after browser-pack finished
                if (isset($i['source']->getMapContentsData(false)->sources[0])) {
                    $i['sourceFile'] = $i['source']->getMapContentsData(false)->sources[0];
                }
                $i['source'] = $i['source']->getFileContentsInlineMap(false);
            }
            $contents = 'window.require = '.Kwf_Assets_CommonJs_BrowserPack::pack(array_values($commonJsData));
            $map = Kwf_SourceMaps_SourceMap::createFromInline($contents);
            $fileContents = $map->getFileContents();
            $fileContents .= ";\n";
            $map->setFileContents($fileContents);
            $data = $map->getMapContentsData(false);
            unset($data->sourcesContent); //drop fake sourcesContent (see comment above)
            if ($data->sources[0] == 'node_modules/browser-pack/_prelude.js') {
                $data->sources[0] = '/assets/web/node_modules/browser-pack/_prelude.js';
            }
            $packageMap->concat($map);
        }

        // ***** non-commonjs, css
        $filterMimeType = $mimeType;
        foreach ($this->_getFilteredUniqueDependencies($filterMimeType) as $dep) {
            if ($dep->getIncludeInPackage()) {
                if (!(($mimeType == 'text/javascript' || $mimeType == 'text/javascript; defer') && $dep->isCommonJsEntry())) {
                    $map = $this->_getFilteredDependencyContents($dep, $mimeType);
                    $data = $map->getMapContentsData(false);
                    if (isset($data->{'_x_org_koala-framework_trlData'})) {
                        $trlData = array_merge($trlData, $data->{'_x_org_koala-framework_trlData'});
                    }
                    if (strpos($map->getFileContents(), "//@ sourceMappingURL=") !== false && strpos($map->getFileContents(), "//# sourceMappingURL=") !== false) {
                        throw new Kwf_Exception("contents must not contain sourceMappingURL");
                    }
                    $sourcesCount = 0;
                    $packageData = $packageMap->getMapContentsData(false);
                    if (isset($packageData->sources)) {
                        $sourcesCount = count($packageData->sources);
                    }
                    unset($packageData);

                    // $ret .= "/* *** $dep */\n"; // attention: commenting this in breaks source maps
                    $packageMap->concat($map);

                    if (isset($data->{'_x_org_koala-framework_sourcesContent'})) {
                        $packageMapData = $packageMap->getMapContentsData(false);
                        if (!isset($packageMapData->{'_x_org_koala-framework_sourcesContent'})) {
                            $packageMapData->{'_x_org_koala-framework_sourcesContent'} = array();
                        }
                        //copy sourcesContent to packageMap with $sourcesCount offset
                        foreach ($data->{'_x_org_koala-framework_sourcesContent'} as $k=>$i) {
                            $packageMapData->{'_x_org_koala-framework_sourcesContent'}[$k+$sourcesCount] = $i;
                        }
                    }
                }
            }
        }

        if ($mimeType == 'text/javascript' || $mimeType == 'text/javascript; defer') {
            if ($uniquePrefix = Kwf_Config::getValue('application.uniquePrefix')) {
                $packageMap = Kwf_Assets_Package_Filter_UniquePrefix::filter($packageMap, $uniquePrefix);
            }
        }

        foreach ($this->getProviderList()->getFilters() as $filter) {
            if ($filter->getExecuteFor() == Kwf_Assets_Filter_Abstract::EXECUTE_FOR_PACKAGE
                && $filter->getMimeType() == $mimeType
            ) {
                $packageMap = $filter->filter($packageMap);
            }
        }

        $data = $packageMap->getMapContentsData(false);
        $data->sourcesContent = array();
        foreach ($data->sources as $k=>$i) {
            if (substr($i, 0, 8) != '/assets/') {
                throw new Kwf_Exception("Source path doesn't start with /assets/: $i");
            }
            $i = substr($i, 8);

            if (isset($data->{'_x_org_koala-framework_sourcesContent'}[$k])) {
                $data->sourcesContent[$k] = $data->{'_x_org_koala-framework_sourcesContent'}[$k];
            } else {
                $i = new Kwf_Assets_Dependency_File($this->_providerList, $i);
                $data->sourcesContent[$k] = $i->getContentsSourceString();
            }
        }

        return array(
            'contents' => $packageMap,
            'trlData' => $trlData,
        );
    }

    public function getPackageContents($mimeType, $language, $includeSourceMapComment = true)
    {
        if (!Kwf_Assets_BuildCache::getInstance()->building && !Kwf_Config::getValue('assets.lazyBuild')) {
            if (Kwf_Exception_Abstract::isDebug()) {
                //proper error message on development server
                throw new Kwf_Exception("Building assets is disabled (assets.lazyBuild). Please upload build contents.");
            } else {
                throw new Kwf_Exception_NotFound();
            }
        }

        if (!isset($this->_packageContentsCache[$mimeType])) {
            $this->_packageContentsCache[$mimeType] = $this->_buildPackageContents($mimeType);
        }

        $packageMap = $this->_packageContentsCache[$mimeType]['contents'];
        $trlData = $this->_packageContentsCache[$mimeType]['trlData'];


        if (($mimeType == 'text/javascript' || $mimeType == 'text/javascript; defer') && $trlData) {
            $js = "";
            $uniquePrefix = Kwf_Config::getValue('application.uniquePrefix');
            if ($uniquePrefix) {
                $js = "if (typeof window.$uniquePrefix == 'undefined') window.$uniquePrefix = {};";
                $uniquePrefix = "window.$uniquePrefix.";
                $js .= "if (!{$uniquePrefix}_kwfTrlData) {$uniquePrefix}_kwfTrlData={};";
            } else {
                $js .= "if (!window._kwfTrlData) window._kwfTrlData={};";
            }
            foreach ($trlData as $i) {
                $key = $i->type.'.'.$i->source;
                if (isset($i->context)) $key .= '.'.$i->context;
                $key .= '.'.str_replace("'", "\\'", $i->text);
                $method = $i->type;
                if ($i->source == 'kwf') $method .= 'Kwf';
                if ($i->type == 'trl') {
                    $trlText = Kwf_Trl::getInstance()->$method($i->text, array(), $language);
                    $js .= "{$uniquePrefix}_kwfTrlData['$key']='".str_replace("'", "\\'", $trlText)."';";
                } else if ($i->type == 'trlc') {
                    $trlText = Kwf_Trl::getInstance()->$method($i->context, $i->text, array(), $language);
                    $js .= "{$uniquePrefix}_kwfTrlData['$key']='".str_replace("'", "\\'", $trlText)."';";
                } else if ($i->type == 'trlp') {
                    $trlText = Kwf_Trl::getInstance()->getTrlpValues(null, $i->text, $i->plural, $i->source, $language);
                    $js .= "{$uniquePrefix}_kwfTrlData['$key']='".str_replace("'", "\\'", $trlText['single'])."';";
                    $js .= "{$uniquePrefix}_kwfTrlData['$key.plural']='".str_replace("'", "\\'", $trlText['plural'])."';";
                } else if ($i->type == 'trlcp') {
                    $trlText = Kwf_Trl::getInstance()->getTrlpValues($i->context, $i->text, $i->plural, $i->source, $language);
                    $js .= "{$uniquePrefix}_kwfTrlData['$key']='".str_replace("'", "\\'", $trlText['single'])."';";
                    $js .= "{$uniquePrefix}_kwfTrlData['$key.plural']='".str_replace("'", "\\'", $trlText['plural'])."';";
                } else {
                    throw new Kwf_Exception("Unknown trl type");
                }
            }
            $map = Kwf_SourceMaps_SourceMap::createEmptyMap($js."\n");
            $map->concat($packageMap);
            $packageMap = $map;
        }

        if ($mimeType == 'text/javascript') $ext = 'js';
        else if ($mimeType == 'text/javascript; defer') $ext = 'defer.js';
        else if ($mimeType == 'text/css') $ext = 'css';
        else throw new Kwf_Exception("Invalid mimeType: '$mimeType'");
        $packageMap->setFile($this->getPackageUrl($ext, $language));
        return $packageMap;
    }

    private function _getChunkedContentsCount($mimeType, $language)
    {
        return 1;
    }

    private function _getChunkedContents($mimeType, $language)
    {
        throw new Kwf_Exception("no chunks enabled");
    }

    public function toUrlParameter()
    {
        return get_class($this->_providerList).':'.$this->_dependencyName;
    }

    public static function fromUrlParameter($class, $parameter)
    {
        $param = explode(':', $parameter);
        $providerList = $param[0];
        $ret = new $class(new $providerList, $param[1]);
        return $ret;
    }

    public function getPackageUrl($ext, $language)
    {
        return Kwf_Setup::getBaseUrl().'/assets/dependencies/'.get_class($this).'/'.$this->toUrlParameter()
            .'/'.$language.'/'.$ext.'?v='.Kwf_Assets_Dispatcher::getInstance()->getAssetsVersion();
    }

    public function getPackageUrlsCacheId($mimeType, $language)
    {
        $ret = $this->_getCacheId($mimeType);
        if (!$ret) return $ret;
        return 'depPckUrls_'.$ret.'_'.$language;
    }

    public function getPackageUrls($mimeType, $language)
    {
        $cacheId = $this->getPackageUrlsCacheId($mimeType, $language);
        $ret = Kwf_Assets_BuildCache::getInstance()->load($cacheId);
        if ($ret !== false) {
            if (Kwf_Setup::getBaseUrl()) {
                foreach ($ret as $k=>$i) {
                    $ret[$k] = Kwf_Setup::getBaseUrl().$i;
                }
            }
            return $ret;
        }

        return $this->_buildPackageUrls($mimeType, $language);
    }

    protected function _buildPackageUrls($mimeType, $language)
    {
        if (!Kwf_Assets_BuildCache::getInstance()->building && !Kwf_Config::getValue('assets.lazyBuild')) {
            throw new Kwf_Exception("Building assets is disabled (assets.lazyBuild). Please upload build contents.");
        }

        if ($mimeType == 'text/javascript') $ext = 'js';
        else if ($mimeType == 'text/javascript; defer') $ext = 'defer.js';
        else if ($mimeType == 'text/css') $ext = 'css';
        else throw new Kwf_Exception_NotYetImplemented();

        $ret = array();
        $hasContents = false;
        $includesDependencies = array();
        foreach ($this->_getFilteredUniqueDependencies($mimeType) as $i) {
            if (!$i->getIncludeInPackage()) {
                if (in_array($i, $includesDependencies, true)) {
                    //include dependency only once
                    continue;
                }
                $includesDependencies[] = $i;
                if ($i instanceof Kwf_Assets_Dependency_HttpUrl) {
                    $ret[] = $i->getUrl();
                } else {
                    throw new Kwf_Exception('Invalid dependency that should not be included in package');
                }
            } else {
                $hasContents = true;
            }
        }

        if ($hasContents) {
            $chunks = $this->_getChunkedContentsCount($mimeType, $language);
            if ($chunks > 1) {
                $urls = array();
                for ($i=0; $i<$chunks; $i++) {
                    $urls[] = $this->getPackageUrl($i.'.'.$ext, $language);
                }
            } else {
                $urls = array($this->getPackageUrl($ext, $language));
            }
            $ret = array_merge($urls, $ret);
        }

        return $ret;
    }

    public function serialize()
    {
        throw new Kwf_Exception("you should not serialize/cache Package, it does everything lazily");
    }

    public function unserialize($serialized)
    {
    }

    public function getUrlContents($extension, $language)
    {
        $sourceMap = false;
        if (substr($extension, -4) == '.map') {
            $extension = substr($extension, 0, -4);
            $sourceMap = true;
        }
        if ($extension == 'js') $mimeType = 'text/javascript';
        else if ($extension == 'defer.js') $mimeType = 'text/javascript; defer';
        else if (substr($extension, -3) == 'css') $mimeType = 'text/css';
        else throw new Kwf_Exception_NotFound();

        if ($mimeType == 'text/css' && $extension != 'css') {
            $chunkNum = substr($extension, 0, -4);
            $chunks = $this->_getChunkedContents($mimeType, $language);
            $map = $chunks[$chunkNum];
        } else {
            $map = $this->getPackageContents($mimeType, $language);
        }
        if (!$sourceMap) {
            $contents = $map->getFileContents();

            if ($mimeType == 'text/javascript' || $mimeType == 'text/javascript; defer') {
                $contents .= "\n//# sourceMappingURL=".$this->getPackageUrl($extension.'.map', $language)."\n";
            } else if ($mimeType == 'text/css') {
                $contents .= "\n/*# sourceMappingURL=".$this->getPackageUrl($extension.'.map', $language)." */\n";
            }

            if ($extension == 'js' || $extension == 'defer.js') $mimeType = 'text/javascript; charset=utf-8';
            else if (substr($extension, -3) == 'css') $mimeType = 'text/css; charset=utf-8';
        } else {
            $contents = $map->getMapContents(false);
            $mimeType = 'application/json';
        }
        $ret = array(
            'contents' => $contents,
            'mimeType' => $mimeType,
            'mtime' => time()
        );
        return $ret;
    }
}
