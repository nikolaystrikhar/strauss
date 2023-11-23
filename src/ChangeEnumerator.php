<?php
/**
 * The purpose of this class is only to find changes that should be made.
 * i.e. classes and namespaces to change.
 * Those recorded are updated in a later step.
 */

namespace BrianHenryIE\Strauss;

use BrianHenryIE\Strauss\Composer\ComposerPackage;
use BrianHenryIE\Strauss\Composer\Extra\StraussConfig;

class ChangeEnumerator
{

    protected string $namespacePrefix;
    protected string $classmapPrefix;
    /**
     *
     * @var string[]
     */
    protected array $excludePackagesFromPrefixing = array();

    /** @var string[]  */
    protected array $excludeNamespacesFromPrefixing = array();

    /** @var string[]  */
    protected array $excludeFilePatternsFromPrefixing = array();

    /** @var string[]  */
    protected array $namespaceReplacementPatterns = array();

    /** @var string[] */
    protected array $discoveredNamespaces = [];

    /** @var string[] */
    protected array $discoveredClasses = [];

    /** @var string[] */
    protected array $discoveredConstants = [];

    /**
     * ChangeEnumerator constructor.
     * @param \BrianHenryIE\Strauss\Composer\Extra\StraussConfig $config
     */
    public function __construct(StraussConfig $config)
    {
        $this->namespacePrefix = $config->getNamespacePrefix();
        $this->classmapPrefix = $config->getClassmapPrefix();

        $this->excludePackagesFromPrefixing = $config->getExcludePackagesFromPrefixing();
        $this->excludeNamespacesFromPrefixing = $config->getExcludeNamespacesFromPrefixing();
        $this->excludeFilePatternsFromPrefixing = $config->getExcludeFilePatternsFromPrefixing();

        $this->namespaceReplacementPatterns = $config->getNamespaceReplacementPatterns();
    }

    /**
     * TODO: Order by longest string first. (or instead, record classnames with their namespaces)
     *
     * @return string[]
     */
    public function getDiscoveredNamespaces(?string $namespacePrefix = ''): array
    {
        $discoveredNamespaceReplacements = [];

        // When running subsequent times, try to discover the original namespaces.
        // This is naive: it will not work where namespace replacement patterns have been used.
        foreach ($this->discoveredNamespaces as $key => $value) {
            $unprefixed = str_starts_with($this->namespacePrefix, $key)
                ? ltrim(substr($key, strlen($this->namespacePrefix)), '\\')
                : $key;
            $discoveredNamespaceReplacements[ $unprefixed ] = $value;
        }

        uksort($discoveredNamespaceReplacements, function ($a, $b) {
            return strlen($a) <=> strlen($b);
        });

        return $discoveredNamespaceReplacements;
    }

    /**
     * @return string[]
     */
    public function getDiscoveredClasses(?string $classmapPrefix = ''): array
    {
        unset($this->discoveredClasses['ReturnTypeWillChange']);

        $discoveredClasses = array_filter(
            array_keys($this->discoveredClasses),
            function (string $replacement) use ($classmapPrefix) {
                return empty($classmapPrefix) || ! str_starts_with($replacement, $classmapPrefix);
            }
        );

        return $discoveredClasses;
    }

    /**
     * @return string[]
     */
    public function getDiscoveredConstants(?string $constantsPrefix = ''): array
    {
        $discoveredConstants = array_filter(
            array_keys($this->discoveredConstants),
            function (string $replacement) use ($constantsPrefix) {
                return empty($constantsPrefix) || ! str_starts_with($replacement, $constantsPrefix);
            }
        );

        return $discoveredConstants;
    }

    /**
     * @param string $absoluteTargetDir
     * @param array<string,array{dependency:ComposerPackage,sourceAbsoluteFilepath:string,targetRelativeFilepath:string}> $filesArray
     */
    public function findInFiles($absoluteTargetDir, $filesArray): void
    {
        foreach ($filesArray as $relativeFilepath => $fileArray) {
            $package = $fileArray['dependency'];
            foreach ($this->excludePackagesFromPrefixing as $excludePackagesName) {
                if ($package->getPackageName() === $excludePackagesName) {
                    continue 2;
                }
            }

            foreach ($this->excludeFilePatternsFromPrefixing as $excludeFilePattern) {
                if (1 === preg_match($excludeFilePattern, $relativeFilepath)) {
                    continue 2;
                }
            }

            $filepath = $absoluteTargetDir . $relativeFilepath;

            // TODO: use flysystem
            // $contents = $this->filesystem->read($targetFile);

            $contents = file_get_contents($filepath);
            if (false === $contents) {
                throw new \Exception("Failed to read file at {$filepath}");
            }

            $this->find($contents);
        }
    }


    /**
     * TODO: Don't use preg_replace_callback!
     *
     * @param string $contents
     *
     * @return string $contents
     */
    public function find(string $contents): string
    {

        // If the entire file is under one namespace, all we want is the namespace.
        // If there were more than one namespace, it would appear as `namespace MyNamespace { ...`,
        // a file with only a single namespace will appear as `namespace MyNamespace;`.
        $singleNamespacePattern = '/
            (<?php|\r\n|\n)                                              # A new line or the beginning of the file.
            \s*                                                          # Allow whitespace before
            namespace\s+(?<namespace>[0-9A-Za-z_\x7f-\xff\\\\]+)[\s\S]*; # Match a single namespace in the file.
        /x'; //  # x: ignore whitespace in regex.
        if (1 === preg_match($singleNamespacePattern, $contents, $matches)) {
            $this->addDiscoveredNamespaceChange($matches['namespace']);
            return $contents;
        }

        if (0 < preg_match_all('/\s*define\s*\(\s*["\']([^"\']*)["\']\s*,\s*["\'][^"\']*["\']\s*\)\s*;/', $contents, $constants)) {
            foreach ($constants[1] as $constant) {
                $this->discoveredConstants[$constant] = $constant;
            }
        }

        // TODO traits

        // TODO: Is the ";" in this still correct since it's being taken care of in the regex just above?
        // Looks like with the preceding regex, it will never match.


        return preg_replace_callback(
            '
			~											# Start the pattern
				[\r\n]+\s*namespace\s+([a-zA-Z0-9_\x7f-\xff\\\\]+)[;{\s\n]{1}[\s\S]*?(?=namespace|$) 
														# Look for a preceding namespace declaration, 
														# followed by a semicolon, open curly bracket, space or new line
														# up until a 
														# potential second namespace declaration or end of file.
														# if found, match that much before continuing the search on
				|										# the remainder of the string.
				\/\*[\s\S]*?\*\/ |                      # Skip multiline comments
				^\s*\/\/.*$	|   						# Skip single line comments
				\s*										# Whitespace is allowed before 
				(?:abstract\sclass|class|interface)\s+	# Look behind for class, abstract class, interface
				([a-zA-Z0-9_\x7f-\xff]+)				# Match the word until the first non-classname-valid character
				\s?										# Allow a space after
				(?:{|extends|implements|\n|$)			# Class declaration can be followed by {, extends, implements 
														# or a new line
			~x', //                                     # x: ignore whitespace in regex.
            function ($matches) {

                // If we're inside a namespace other than the global namespace:
                if (1 === preg_match('/\s*namespace\s+[a-zA-Z0-9_\x7f-\xff\\\\]+[;{\s\n]{1}.*/', $matches[0])) {
                    $this->addDiscoveredNamespaceChange($matches[1]);
                    return $matches[0];
                }

                if (count($matches) < 3) {
                    return $matches[0];
                }

                // TODO: Why is this [2] and not [1] (which seems to be always empty).
                $this->discoveredClasses[$matches[2]] = $matches[2];
                return $matches[0];
            },
            $contents
        );
    }

    protected function addDiscoveredNamespaceChange(string $namespace): void
    {

        foreach ($this->excludeNamespacesFromPrefixing as $excludeNamespace) {
            if (0 === strpos($namespace, $excludeNamespace)) {
                return;
            }
        }

        foreach ($this->namespaceReplacementPatterns as $namespaceReplacementPattern => $replacement) {
            $prefixed = preg_replace($namespaceReplacementPattern, $replacement, $namespace);

            if ($prefixed !== $namespace) {
                $this->discoveredNamespaces[$namespace] = $prefixed;
                return;
            }
        }

        $this->discoveredNamespaces[$namespace] = $this->namespacePrefix . '\\'. $namespace;
    }
}
