<?php

/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <https://www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Herbie\Menu\Page;

use Herbie\Cache\CacheInterface;
use Herbie\Iterator\RecursiveDirectoryIterator;
use Herbie\Loader\FrontMatterLoader;
use Herbie\Menu\Page\Iterator\SortableIterator;

class Builder
{

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var array
     */
    protected $paths;

    /**
     * @var array
     */
    protected $extensions;

    /**
     * @var array
     */
    protected $indexFiles;

    /**
     * @param array $paths
     * @param array $extensions
     */
    public function __construct(array $paths, array $extensions)
    {
        $this->paths = $paths;
        $this->extensions = $extensions;
        $this->indexFiles = [];
    }

    /**
     * @param CacheInterface $cache
     */
    public function setCache(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * @return void
     */
    public function unsetCache()
    {
        $this->cache = null;
    }

    /**
     * @return Collection
     */
    public function buildCollection($customPath=false)
    {
        $_paths = $customPath ? $customPath : $this->paths;

        $collection = $this->restoreCollection();
        if (!$collection->fromCache || $customPath) {
            foreach ($_paths as $alias => $path) {

                $this->indexFiles = [];
                foreach ($this->getIterator($path) as $fileInfo) {
                    // index file as describer for parent folder
                    if ($fileInfo->isDir()) {
                        // get first index file only
                        foreach (glob($fileInfo->getPathname() . '/*index.*') as $indexFile) {
                            $this->indexFiles[] = $indexFile;
                            $relPathname = $fileInfo->getRelativePathname() . '/' . basename($indexFile);
                            $item = $this->createItem($indexFile, $relPathname, $alias);
                            if($item) $collection->addItem($item);
                            break;
                        }
                        // other files
                    } else {
                        if (!$this->isValid($fileInfo->getPathname(), $fileInfo->getExtension())) {
                            continue;
                        }
                        $item = $this->createItem($fileInfo->getPathname(), $fileInfo->getRelativePathname(), $alias);
                        if($item) $collection->addItem($item);
                    }
                }

            }
            if(!$customPath) {
            $this->storeCollection($collection);
        }
        }
        return $collection;
    }

    /**
     * @return Collection
     */
    private function restoreCollection()
    {
        if (is_null($this->cache)) {
            return new Collection();
        }
        $collection = $this->cache->get(__CLASS__);
        if ($collection === false) {
            return new Collection();
        }
        return $collection;
    }

    /**
     * @param $collection
     * @return bool
     */
    private function storeCollection($collection)
    {
        if (is_null($this->cache)) {
            return false;
        }
        $collection->fromCache = true;
        return $this->cache->set(__CLASS__, $collection);
    }


    /**
     * @param string $path
     * @return SortableIterator
     */
    protected function getIterator($path)
    {
        // recursive iterators
        $directoryIterator = new RecursiveDirectoryIterator($path);
        $callback = [new FileFilterCallback($this->extensions), 'call'];
        $filterIterator = new \RecursiveCallbackFilterIterator($directoryIterator, $callback);
        $mode = \RecursiveIteratorIterator::SELF_FIRST;
        $iteratorIterator = new \RecursiveIteratorIterator($filterIterator, $mode);
        return new SortableIterator($iteratorIterator, Iterator\SortableIterator::SORT_BY_NAME);
    }

    /**
     * @param string $absolutePath
     * @param string $extension
     * @return boolean
     */
    protected function isValid($absolutePath, $extension)
    {
        if (!in_array($extension, $this->extensions)) {
            return false;
        }
        if (in_array($absolutePath, $this->indexFiles)) {
            return false;
        }
        return true;
    }

    /**
     * @param string $absolutePath
     * @param string $relativePath
     * @param string $alias
     * @return Item
     */
    protected function createItem($absolutePath, $relativePath, $alias)
    {
        $loader = new FrontMatterLoader();
        $data = $loader->load($absolutePath);

        $trimExtension = empty($data['keep_extension']);
        $route = $this->createRoute($relativePath, $trimExtension);

        // handle translations
        $requestedLang = $this->extractLanguageFromUri();
        $detectedlang = $this->extractLanguageFromPath($relativePath);
        if($requestedLang == $detectedlang){
            // rewrite route
            $route = $this->changeRouteForTranslations($route, $detectedlang);
            // use this in your templates
            $data['language'] = $detectedlang;
        } else {
            // Skip 'foreign' items
            return null;
        }

        $data['path'] = $alias . '/' . $relativePath;
        $data['route'] = $route;
        $item = new Item($data);

        if (empty($item->modified)) {
            $item->modified = date('c', filemtime($absolutePath));
        }
        if (empty($item->date)) {
            $item->date = date('c', filectime($absolutePath));
        }
        if (!isset($item->hidden)) {
            $item->hidden = !preg_match('/^[0-9]+-/', basename($relativePath));
        }
        return $item;
    }

    /**
     * @param string $path
     * @param bool $trimExtension
     * @return string
     */
    protected function createRoute($path, $trimExtension = false)
    {
        // strip left unix AND windows dir separator
        $route = ltrim($path, '\/');

        // remove leading numbers (sorting) from url segments
        $segments = explode('/', $route);
        foreach ($segments as $i => $segment) {
            $segments[$i] = preg_replace('/^[0-9]+-/', '', $segment);
        }
        $imploded = implode('/', $segments);

        // trim extension
        $pos = strrpos($imploded, '.');
        if ($trimExtension && ($pos !== false)) {
            $imploded = substr($imploded, 0, $pos);
        }

        // remove last "/index" from route
        $route = preg_replace('#\/index$#', '', trim($imploded, '\/'));

        // handle index route
        return ($route == 'index') ? '' : $route;
    }

    private function changeRouteForTranslations($route, $lang)
    {
        if( 'default' !== $lang )
        {
            $route = str_replace('/index.'.$lang, '', $route);
            $route = str_replace('.'.$lang, '', $route);
            $route = ( $route == 'index')
                ? $lang
                : $lang.DIRECTORY_SEPARATOR.$route;
        }
        return $route;
    }

    /**
     * @param string $alias
     * @return string
     */
    private function extractLanguageFromPath($alias)
    {
        $filename = basename($alias);
        if (preg_match('/^.*\.([a-z]{2})\..*$/', $filename, $matches) ) {
            if(in_array($matches[1], array('en'))) {
                return $matches[1];
            }
        }
        return 'default';
    }

    /**
     * @return string
     */
    private function extractLanguageFromUri()
    {
        $lang = 'default';

        if(isset($_REQUEST['L'])){
            $requery = str_replace('L='.$_REQUEST['L'],'', $_SERVER['QUERY_STRING']);
            $redirect = '/'.$_REQUEST['L'].str_replace($_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI']);
            $redirect = rtrim($redirect, '\/?&');
            die(header( 'Location: '.$redirect.($requery ? '?'.$requery : '') ));
        }

        $test = explode('/', trim($_SERVER['REQUEST_URI'],'/'));
        if($test[0] != '' && in_array($test[0], array('de','en'))){
            $lang = $test[0];
        };

        return $lang;
    }
}
