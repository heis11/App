
<?php

// ======================================================
// HEISVERSE - Main API
// ======================================================

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/movie-sources.php';

// ======================================================
// JSON RESPONSE HELPERS
// ======================================================

function hvJsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE |
        JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    exit;
}

function hvFail(string $message, int $status = 400): void
{
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    hvJsonResponse(['error' => $message], $status);
}

// ======================================================
// GENRES
// ======================================================

$HV_GENRES = [
    28 => 'Action',
    12 => 'Adventure',
    35 => 'Comedy',
    18 => 'Drama',
    27 => 'Horror',
    10749 => 'Romance',
    878 => 'Sci-Fi',
    16 => 'Animation',
    14 => 'Fantasy',
    53 => 'Thriller',
    99 => 'Documentary',
    80 => 'Crime',
    10751 => 'Family',
    9648 => 'Mystery',
    36 => 'History',
    10752 => 'War',
    37 => 'Western',
    10402 => 'Music',
    10770 => 'TV Movie'
];

// ======================================================
// TMDB REQUEST
// Requires tmdb_request() in includes/functions.php
// ======================================================

function hvTmdb(string $path, array $query = []): array
{
    if (!function_exists('tmdb_request')) {
        error_log('HEISVERSE: tmdb_request() is missing.');
        hvFail('TMDB service is not configured correctly.', 500);
    }

    try {
        $result = tmdb_request($path, $query);

        if (!is_array($result)) {
            throw new RuntimeException('Invalid TMDB response.', 502);
        }

        return $result;

    } catch (Throwable $e) {
        error_log('HEISVERSE TMDB error: ' . $e->getMessage());

        $status = (int) $e->getCode();

        if ($status < 400 || $status > 599) {
            $status = 502;
        }

        hvFail('Could not retrieve movie information from TMDB.', $status);
    }
}

// ======================================================
// NORMALIZE MOVIE DATA
// ======================================================

function hvNormalizeMovie(array $movie): array
{
    global $HV_GENRES;

    $genreNames = [];

    foreach ($movie['genre_ids'] ?? [] as $genreId) {
        $genreId = (int) $genreId;

        if (isset($HV_GENRES[$genreId])) {
            $genreNames[] = $HV_GENRES[$genreId];
        }
    }

    return [
        'id' => (int) ($movie['id'] ?? 0),
        'title' => $movie['title'] ?? 'Untitled',
        'year' => substr((string) ($movie['release_date'] ?? ''), 0, 4),
        'poster' => $movie['poster_path'] ?? null,
        'backdrop' => $movie['backdrop_path'] ?? null,
        'rating' => round((float) ($movie['vote_average'] ?? 0), 1),
        'overview' => $movie['overview'] ?? '',
        'genres' => $genreNames
    ];
}

// ======================================================
// MOVIE LIST RESPONSE
// ======================================================

function hvListOut(array $response): void
{
    $results = $response['results'] ?? [];

    if (!is_array($results)) {
        $results = [];
    }

    $results = array_filter($results, 'is_array');

    hvJsonResponse([
        'page' => max(1, (int) ($response['page'] ?? 1)),
        'total_pages' => min(
            500,
            max(1, (int) ($response['total_pages'] ?? 1))
        ),
        'results' => array_map('hvNormalizeMovie', $results)
    ]);
}

// ======================================================
// DATABASE ERROR HANDLING
// ======================================================

function hvCatalog(callable $callback): void
{
    try {
        $callback();

    } catch (Throwable $e) {
        error_log('HEISVERSE database/catalog error: ' . $e->getMessage());

        hvFail(
            'The movie catalog could not be loaded. Check the database connection and database tables.',
            503
        );
    }
}

// ======================================================
// REQUEST PARAMETERS
// ======================================================

$action = trim((string) ($_GET['action'] ?? ''));

$page = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT
);

if ($page === false || $page < 1) {
    $page = 1;
}

$page = min(500, $page);

// ======================================================
// API ROUTES
// ======================================================

try {

    switch ($action) {

        // ==============================================
        // TRENDING MOVIES
        // ==============================================

        case 'trending':

            hvListOut(
                hvTmdb('/trending/movie/week', [
                    'page' => $page
                ])
            );

            break;

        // ==============================================
        // DISCOVER MOVIES
        // ==============================================

        case 'discover':

            $query = [
                'sort_by' => 'popularity.desc',
                'include_adult' => 'false',
                'vote_count.gte' => 40,
                'page' => $page
            ];

            if (!empty($_GET['africa'])) {
                $query['with_origin_country'] =
                    'NG|GH|ZA|KE|EG|RW|TZ|UG|MA|SN|CI|ET|CM';

                $query['vote_count.gte'] = 5;
            }

            if (isset($_GET['genre']) && $_GET['genre'] !== '') {

                $genreId = filter_var(
                    $_GET['genre'],
                    FILTER_VALIDATE_INT
                );

                if (
                    $genreId === false ||
                    !isset($HV_GENRES[$genreId])
                ) {
                    hvFail('Unknown genre.');
                }

                $query['with_genres'] = $genreId;
            }

            hvListOut(
                hvTmdb('/discover/movie', $query)
            );

            break;

        // ==============================================
        // SEARCH MOVIES
        // ==============================================

        case 'search':

            $term = trim((string) ($_GET['q'] ?? ''));

            if (
                $term === '' ||
                strlen($term) > 300 ||
                (function_exists('mb_strlen') && mb_strlen($term) > 100)
            ) {
                hvFail('Enter a search term of 1 to 100 characters.');
            }

            hvListOut(
                hvTmdb('/search/movie', [
                    'query' => $term,
                    'include_adult' => 'false',
                    'page' => $page
                ])
            );

            break;

        // ==============================================
        // TMDB MOVIE DETAILS
        // ==============================================

        case 'detail':

            $id = filter_var(
                $_GET['id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            if ($id === false || $id < 1) {
                hvFail('Invalid movie ID.');
            }

            $region = strtoupper(
                preg_replace(
                    '/[^A-Za-z]/',
                    '',
                    (string) ($_GET['region'] ?? DEFAULT_REGION)
                )
            );

            if (strlen($region) !== 2) {
                $region = DEFAULT_REGION;
            }

            $movie = hvTmdb(
                '/movie/' . $id,
                [
                    'append_to_response' => 'videos,watch/providers'
                ]
            );

            $output = hvNormalizeMovie($movie);

            $output['genres'] = array_map(
                fn($genre) => $genre['name'] ?? '',
                $movie['genres'] ?? []
            );

            $output['runtime'] = (int) ($movie['runtime'] ?? 0);
            $output['tagline'] = $movie['tagline'] ?? '';

            $output['tmdbUrl'] =
                'https://www.themoviedb.org/movie/' . $id;

            // Find an official YouTube trailer where available.
            $trailer = null;

            foreach ($movie['videos']['results'] ?? [] as $video) {

                if (
                    ($video['site'] ?? '') === 'YouTube' &&
                    ($video['type'] ?? '') === 'Trailer' &&
                    preg_match(
                        '/^[\w-]{6,20}$/',
                        (string) ($video['key'] ?? '')
                    )
                ) {
                    if (
                        $trailer === null ||
                        !empty($video['official'])
                    ) {
                        $trailer = $video['key'];
                    }
                }
            }

            $output['trailer'] = $trailer;

            // Streaming provider information.
            $providers =
                $movie['watch/providers']['results'][$region] ?? null;

            if ($providers) {

                $getNames = function (string $type) use ($providers): array {

                    return array_values(
                        array_filter(
                            array_map(
                                fn($provider) =>
                                    $provider['provider_name'] ?? '',
                                $providers[$type] ?? []
                            )
                        )
                    );
                };

                $output['providers'] = [
                    'region' => $region,
                    'link' => $providers['link'] ?? null,
                    'free' => $getNames('free'),
                    'ads' => $getNames('ads'),
                    'flatrate' => $getNames('flatrate'),
                    'rent' => $getNames('rent'),
                    'buy' => $getNames('buy')
                ];

            } else {
                $output['providers'] = null;
            }

            $output['region'] = $region;

            hvJsonResponse($output);

            break;

        // ==============================================
        // PUBLISHED MOVIE CATALOG
        // Requires database helper functions
        // ==============================================

        case 'catalog':

            hvCatalog(function () use ($page) {

                $perPage = 24;

                $where = [published_sql('m')];
                $args = [];

                $term = trim((string) ($_GET['q'] ?? ''));

                if ($term !== '') {

                    if (
                        (function_exists('mb_strlen') && mb_strlen($term) > 100) ||
                        strlen($term) > 300
                    ) {
                        hvFail('Search term is too long.');
                    }

                    $where[] = 'm.title LIKE ?';

                    $args[] =
                        '%' .
                        addcslashes($term, '%_\\') .
                        '%';
                }

                $genre = trim((string) ($_GET['genre'] ?? ''));

                if ($genre !== '') {

                    $where[] = 'FIND_IN_SET(?, m.genres) > 0';

                    $args[] = function_exists('mb_substr')
                        ? mb_substr($genre, 0, 30)
                        : substr($genre, 0, 30);
                }

                $whereSql = implode(' AND ', $where);

                $countStatement = db()->prepare(
                    "SELECT COUNT(*) FROM movies m WHERE $whereSql"
                );

                $countStatement->execute($args);

                $total = (int) $countStatement->fetchColumn();

                $offset = ($page - 1) * $perPage;

                $statement = db()->prepare(
                    "SELECT m.*
                     FROM movies m
                     WHERE $whereSql
                     ORDER BY m.published_at DESC, m.id DESC
                     LIMIT $perPage OFFSET $offset"
                );

                $statement->execute($args);

                $movies = array_map(
                    'catalog_row',
                    $statement->fetchAll()
                );

                $result = [
                    'page' => $page,
                    'total_pages' => max(
                        1,
                        (int) ceil($total / $perPage)
                    ),
                    'results' => $movies
                ];

                if ($page === 1) {

                    $genres = [];

                    $genreStatement = db()->query(
                        'SELECT m.genres
                         FROM movies m
                         WHERE ' . published_sql('m')
                    );

                    foreach ($genreStatement as $row) {

                        foreach (
                            explode(',', $row['genres'] ?? '') as $name
                        ) {
                            $name = trim($name);

                            if ($name !== '') {
                                $genres[$name] = true;
                            }
                        }
                    }

                    $result['genres'] = array_keys($genres);

                    sort($result['genres']);
                }

                hvJsonResponse($result);
            });

            break;

        // ==============================================
        // PUBLISHED MOVIE DETAILS
        // ==============================================

        case 'catalog_detail':

            hvCatalog(function () {

                $id = filter_var(
                    $_GET['id'] ?? 0,
                    FILTER_VALIDATE_INT
                );

                if ($id === false || $id < 1) {
                    hvFail('Invalid movie ID.');
                }

                $statement = db()->prepare(
                    'SELECT m.*
                     FROM movies m
                     WHERE m.id = ?
                     AND ' . published_sql('m')
                );

                $statement->execute([$id]);

                $movie = $statement->fetch();

                if (!$movie) {
                    hvFail('This movie is not available.', 404);
                }

                $output = catalog_row($movie);

                $output['runtime'] = (int) ($movie['runtime'] ?? 0);
                $output['tagline'] = $movie['tagline'] ?? '';
                $output['trailer'] = $movie['trailer_key'] ?? '';

                $rights = rights_options();

                $output['rights'] =
                    $rights[$movie['rights_status']] ?? '';

                // Return only active playback sources.
                $sources = movie_sources_of($id);

                $output['sources'] = array_map(
                    'source_playback',
                    array_values(
                        array_filter(
                            $sources,
                            fn($source) =>
                                (int) ($source['is_active'] ?? 0) === 1
                        )
                    )
                );

                hvJsonResponse($output);
            });

            break;

        // ==============================================
        // UNKNOWN ACTION
        // ==============================================

        default:

            hvFail('Unknown API action.', 404);
    }

} catch (Throwable $e) {

    error_log(
        'HEISVERSE API error: ' .
        $e->getMessage()
    );

    hvFail(
        'An unexpected server error occurred. Check the Render logs.',
        500
    );
}
