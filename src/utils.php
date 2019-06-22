<?php
declare(strict_types=1);

namespace LanguageServer;

use Amp\Loop;
use InvalidArgumentException;
use LanguageServer\FilesFinder\File;
use Throwable;
use function League\Uri\parse;

/**
 * Transforms an absolute file path into a URI as used by the language server protocol.
 *
 * @param string $filepath
 * @return string
 */
function pathToUri(string $filepath): string
{
    $filepath = trim(str_replace('\\', '/', $filepath), '/');
    $parts = explode('/', $filepath);
    // Don't %-encode the colon after a Windows drive letter
    $first = array_shift($parts);
    if (substr($first, -1) !== ':') {
        $first = rawurlencode($first);
    }
    $parts = array_map('rawurlencode', $parts);
    array_unshift($parts, $first);
    $filepath = implode('/', $parts);
    return 'file:///' . $filepath;
}

/**
 * Transforms URI into file path
 *
 * @param string $uri
 * @return string
 */
function uriToPath(string $uri)
{
    $fragments = parse_url($uri);
    if ($fragments === false || !isset($fragments['scheme']) || $fragments['scheme'] !== 'file') {
        throw new InvalidArgumentException("Not a valid file URI: $uri");
    }
    $filepath = urldecode($fragments['path']);
    if (strpos($filepath, ':') !== false) {
        if ($filepath[0] === '/') {
            $filepath = substr($filepath, 1);
        }
        $filepath = str_replace('/', '\\', $filepath);
    }
    return $filepath;
}

/**
 * Throws an exception on the next tick.
 * Useful for letting a promise crash the process on rejection.
 *
 * @param Throwable $err
 * @return void
 */
function crash(Throwable $err)
{
    Loop::run(function () use ($err) {
        throw $err;
    });
}

/**
 * Returns the part of $b that is not overlapped by $a
 * Example:
 *
 *     stripStringOverlap('whatever<?', '<?php') === 'php'
 *
 * @param string $a
 * @param string $b
 * @return string
 */
function stripStringOverlap(string $a, string $b): string
{
    $aLen = strlen($a);
    $bLen = strlen($b);
    for ($i = 1; $i <= $bLen; $i++) {
        if (substr($b, 0, $i) === substr($a, $aLen - $i)) {
            return substr($b, $i);
        }
    }
    return $b;
}

/**
 * Use for sorting an array of URIs by number of segments
 * in ascending order.
 *
 * @param File[] $uriList
 * @return void
 */
function sortUrisLevelOrder(&$uriList)
{
    usort($uriList, function ($a, $b) {
        /** @var $a File */
        /** @var $b File */
        return substr_count(parse($a->getUri())['path'], '/') - substr_count(parse($b->getUri())['path'], '/');
    });
}

/**
 * Checks a document against the composer.json to see if it
 * is a vendored document
 *
 * @param PhpDocument $document
 * @param \stdClass|null $composerJson
 * @return bool
 */
function isVendored(PhpDocument $document, \stdClass $composerJson = null): bool
{
    $path = parse($document->getUri())['path'];
    $vendorDir = getVendorDir($composerJson);
    return strpos($path, "/$vendorDir/") !== false;
}

/**
 * Check a given URI against the composer.json to see if it
 * is a vendored URI
 *
 * @param string $uri
 * @param \stdClass|null $composerJson
 * @return string|null
 */
function getPackageName(string $uri, \stdClass $composerJson = null)
{
    $vendorDir = str_replace('/', '\/', getVendorDir($composerJson));
    preg_match("/\/$vendorDir\/([^\/]+\/[^\/]+)\//", $uri, $matches);
    return $matches[1] ?? null;
}

/**
 * Helper function to get the vendor directory from composer.json
 * or default to 'vendor'
 *
 * @param \stdClass|null $composerJson
 * @return string
 */
function getVendorDir(\stdClass $composerJson = null): string
{
    return $composerJson->config->{'vendor-dir'} ?? 'vendor';
}
