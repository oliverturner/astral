<?php

namespace Astral\Lib;

use Auth;
use Cache;
use Github\ResultPager;
use GrahamCampbell\GitHub\GitHubFactory;

class GithubClient
{
    /** @var int */
    protected $starsCacheExpiry = 120; //minutes

    /** @var int */
    protected $starsPerPage = 30;

    /** @var \GrahamCampbell\GitHub\GitHubFactory */
    protected $client;

    /** @var Github\ResultPager */
    protected $paginator;

    public function __construct($token)
    {
        $this->client = app(GitHubFactory::class)->make(['token' => $token, 'method' => 'token', 'cache' => false, 'backoff' => false]);
        $this->paginator = new ResultPager($this->client);
    }

    /**
     * @param int $page
     *
     * @return array
     */
    public function getStars($page = 1)
    {
        $cacheKey = $this->starsCacheKey();
        $cacheExpiry = $this->starsCacheExpiry;
        $starsArray = [];

        // Check if they're doing a fresh fetch to see if we've cached our stars already
        if ($page == 1 && Cache::has($cacheKey)) {
            $cachedStars = Cache::get($cacheKey);
            // Add a "cached" key so we can check on the front-end whether we should paginate or not. We set it to the number of pages currently cached, so we fetch only what we need in subsequent requests
            $cachedPages = count($cachedStars['stars']);
            $cachedStars['cached'] = (int) ceil($cachedPages / $this->starsPerPage);

            return $cachedStars;
        }

        $stars = $this->paginator->fetch($this->client->me()->starring(), 'all', [$page]);
        $starsArray['stars'] = $stars;
        $paginationInfo = $this->paginator->getPagination();
        if ($this->paginator->hasNext()) {
            $pageCount = $this->getPageCountFromPaginationLink($paginationInfo['last']);
            $starsArray['page_count'] = $pageCount;
        } else {
            // Fetch the last known total
            $cachedStars = Cache::get($cacheKey);
            $starsArray['page_count'] = $cachedStars['page_count'];
        }

        if ($page != 1) {
            $cachedStars = Cache::get($cacheKey);
            // Merge the new stars into the old ones
            $oldStars = $cachedStars['stars'];
            $newStars = $starsArray['stars'];
            $starsArray['stars'] = array_merge($oldStars, $newStars);
        }
        Cache::put($cacheKey, $starsArray, $cacheExpiry);

        return $starsArray;
    }

    /**
     * @return string
     */
    private function starsCacheKey()
    {
        return 'user_'.Auth::id().'.github_stars';
    }

    /**
     * @param int $page
     *
     * @return array
     */
    private function getStarPaginationInfo($page = 1)
    {
        $this->paginator->fetch($this->client->me()->starring(), 'all', [$page]);

        return $this->paginator->getPagination();
    }

    /**
     * @param string $link
     *
     * @return int
     */
    private function getPageCountFromPaginationLink($link)
    {
        try {
            $queryString = explode('?', $link);
            $pageCount = explode('=', $queryString[1]);

            return (int) $pageCount[1];
        } catch (Exception $e) {
            return 1;
        }
    }
}
