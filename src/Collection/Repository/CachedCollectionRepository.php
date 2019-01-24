<?php
/**
 * This file is part of Phraseanet
 *
 * (c) 2005-2016 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace App\Collection\Repository;

use Alchemy\Phrasea\Application;
use App\Collection\Collection;
use App\Collection\CollectionRepository;
use Doctrine\Common\Cache\Cache;

final class CachedCollectionRepository implements CollectionRepository
{

    /**
     * @var Application
     */
    private $app;

    /**
     * @var CollectionRepository
     */
    private $repository;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var string
     */
    private $cacheKey;

    /**
     //* @param Application $application
     * @param CollectionRepository $repository
     * @param Cache $cache
     * @param $cacheKey
     */
    //public function __construct(Application $application, CollectionRepository $repository, Cache $cache, $cacheKey)
    public function __construct(CollectionRepository $repository, Cache $cache, $cacheKey)
    {
        //$this->app = $application;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->cacheKey = $cacheKey;
    }

    /**
     * @return \App\Utils\collection[]
     */
    public function findAll()
    {
        $cacheKey = $this->getCacheKey();
        /** @var \\App\Utils\collection[] $collections */
        $collections = $this->cache->fetch($cacheKey);

        if ($collections === false) {
            $collections = $this->repository->findAll();
            $this->putInCache($cacheKey, $collections);
        } else {
            foreach ($collections as $collection) {
                $collection->hydrate($this->app);
            }
        }

        return $collections;
    }

    /**
     * @param int $collectionId
     * @return \App\Utils\collection|null
     */
    public function find($collectionId)
    {
        $collections = $this->findAll();

        if (isset($collections[$collectionId])) {
            return $collections[$collectionId];
        }

        return null;
    }

    public function save(Collection $collection)
    {
        $this->repository->save($collection);

        $cacheKey = $this->getCacheKey();

        $this->cache->delete($cacheKey);
    }

    public function delete(Collection $collection)
    {
        $this->repository->delete($collection);

        $cacheKey = $this->getCacheKey();

        $this->cache->delete($cacheKey);
    }

    private function putInCache($key, $value)
    {
        $this->cache->save($key, $value);
    }

    /**
     * @return string
     */
    private function getCacheKey()
    {
        $cacheKey = 'collections:' . hash('sha256', $this->cacheKey);

        return $cacheKey;
    }
}
