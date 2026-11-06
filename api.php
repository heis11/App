
<?php
declare(strict_types=1);

// ======================================================
// HEISVERSE - Main API
// ======================================================

// Do not display PHP errors to website visitors.
// Errors are written to the server log instead.
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

function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function fail(string $message, int $status = 400): void
{
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonResponse(['error' => $message], $status);
}

// ======================================================
// GENRES
// ======================================================

$GENRES = [
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
// ======================================================

function tmdb(string $path, array $query = []): array
{
    try {
        $result = tmdb_request($path, $query);

        if (!is_array($result)) {
            throw new RuntimeException('Invalid response from TMDB.', 502);
        }

        return $result;

    } catch (RuntimeException $e) {
        $status = (int) $e->getCode();

        if ($status < 400 || $status > 599) {
            $status = 502;
        }

        error_log('HEISVERSE TMDB error: ' . $e->getMessage());

        fail($e->getMessage(), $status);
    }
}

// ======================================================
// NORMALIZE TMDB MOVIE
// ======================================================

function norm(array $movie): array
{
    global $GENRES;

    $genreNames = [];

    foreach ($movie['genre_ids'] ?? [] as $genreId) {
        $genreId = (int) $genreId;

        if (isset($GENRES[$genreId])) {
            $genreNames[] = $GENRES[$genreId];
        }
    }

    return [
        'id' => (int) ($movie['id'] ?? 0),
        'title' => $movie['title'] ?? 'Untitled',
        'year' => substr($movie['release_date'] ?? '', 0, 4),
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

function listOut(array $response): void
{
    $results = $response['results'] ?? [];

    if (!is_array($results)) {
        $results = [];
    }

    jsonResponse([
        'page' => max(1, (int) ($response['page'] ?? 1)),
        'total_pages' => min(
            500,
            max(1, (int) ($response['total_pages'] ?? 1))
        ),
        'results' => array_map(
            'norm',
            array_filter($results, 'is_array')
        )
    ]);
}

// ======================================================
// DATABASE CATALOG
// ======================================================

function catalogDb(callable $callback): void
{
    try {
        $callback();

    } catch (PDOException $e) {

        error_log('HEISVERSE database error: ' . $e->getMessage());

        // Do not expose database credentials or SQL errors.
        fail(
            'The movie catalog database could not be accessed. Check your database connection and imported tables.',
            503
        );

    } catch (RuntimeException $e) {

        error_log('HEISVERSE catalog error: ' . $e->getMessage());

        $status = (int) $e->getCode();

        if ($status < 400 || $status > 599) {
            $status = 503;
        }

        fail($e->getMessage(), $status);
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

        // --------------------------------------------------
        // TRENDING MOVIES
        // --------------------------------------------------

        case 'trending':

            listOut(
                tmdb('/trending/movie/week', [
                    'page' => $page
                ])
            );

            break;

        // --------------------------------------------------
        // DISCOVER MOVIES
        // --------------------------------------------------

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
                    !isset($GENRES[$genreId])
                ) {
                    fail('Unknown genre.');
                }

                $query['with_genres'] = $genreId;
            }

            listOut(
                tmdb('/discover/movie', $query)
            );

            break;

        // --------------------------------------------------
        // SEARCH MOVIES
        // --------------------------------------------------

        case 'search':

            $term = trim((string) ($_GET['q'] ?? ''));

            if (
                $term === '' ||
                mb_strlen($term) > 100
            ) {
                fail('Type 1 to 100 characters to search.');
            }

            listOut(
                tmdb('/search/movie', [
                    'query' => $term,
                    'include_adult' => 'false',
                    'page' => $page
                ])
            );

            break;

        // --------------------------------------------------
        // TMDB MOVIE DETAILS
        // --------------------------------------------------

        case 'detail':

            $id = filter_var(
                $_GET['id'] ?? 0,
                FILTER_VALIDATE_INT
            );

            if ($id === false || $id < 1) {
                fail('Invalid movie ID.');
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

            $movie = tmdb(
                '/movie/' . $id,
                [
                    'append_to_response' => 'videos,watch/providers'
                ]
            );

            $output = norm(
                $movie + ['genre_ids' => []]
            );

            $output['genres'] = array_map(
                fn($genre) => $genre['name'] ?? '',
                $movie['genres'] ?? []
            );

            $output['runtime'] = (int) ($movie['runtime'] ?? 0);

            $output['tagline'] = $movie['tagline'] ?? '';

            $output['tmdbUrl'] =
                'https://www.themoviedb.org/movie/' . $id;

            // Find a YouTube trailer.
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

            jsonResponse($output);

            break;

        // --------------------------------------------------
        // ADMIN PUBLISHED MOVIE CATALOG
        // --------------------------------------------------

        case 'catalog':

            catalogDb(function () use ($page) {

                $perPage = 24;

                $where = [
                    published_sql('m')
                ];

                $args = [];

                // Search catalog by title.
                $term = trim(
                    (string) ($_GET['q'] ?? '')
                );

                if ($term !== '') {

                    if (mb_strlen($term) > 100) {
                        fail('Search term is too long.');
                    }

                    $where[] = 'm.title LIKE ?';

                    $args[] =
                        '%' .
                        addcslashes($term, '%_\\') .
                        '%';
                }

                // Filter by genre.
                $genre = trim(
                    (string) ($_GET['genre'] ?? '')
                );

                if ($genre !== '') {

                    $where[] = 'FIND_IN_SET(?, m.genres) > 0';

                    $args[] = mb_substr($genre, 0, 30);
                }

                $whereSql = implode(' AND ', $where);

                // Total movie count.
                $countStatement = db()->prepare(
                    "SELECT COUNT(*) FROM movies m WHERE $whereSql"
                );

                $countStatement->execute($args);

                $total = (int) $countStatement->fetchColumn();

                $offset = ($page - 1) * $perPage;

                // Fetch published movies.
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

                // Include available genres on first page.
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

                jsonResponse($result);
            });

            break;

        // --------------------------------------------------
        // PUBLISHED CATALOG MOVIE DETAILS
        // --------------------------------------------------

        case 'catalog_detail':

            catalogDb(function () {

                $id = filter_var(
                    $_GET['id'] ?? 0,
                    FILTER_VALIDATE_INT
                );

                if ($id === false || $id < 1) {
                    fail('Invalid movie ID.');
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
                    fail('This movie is not available.', 404);
                }

                $output = catalog_row($movie);

                $output['runtime'] = (int) ($movie['runtime'] ?? 0);

                $output['tagline'] = $movie['tagline'] ?? '';

                $output['trailer'] = $movie['trailer_key'] ?? '';

                $rights = rights_options();

                $output['rights'] =
                    $rights[$movie['rights_status']] ?? '';

                // Only active playback sources are returned.
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

                jsonResponse($output);
            });

            break;

        // --------------------------------------------------
        // UNKNOWN ACTION
        // --------------------------------------------------

        default:

            fail('Unknown API action.', 404);
    }

} catch (Throwable $e) {

    error_log(
        'HEISVERSE API unexpected error: ' .
        $e->getMessage()
    );

    fail(
        'An unexpected server error occurred. Please try again later.',
        500
    );
}
