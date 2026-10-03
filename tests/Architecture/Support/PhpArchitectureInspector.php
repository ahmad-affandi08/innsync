<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PhpArchitectureInspector
{
    /** @var list<string> */
    private const MODULE_CONTEXTS = [
        'IdentityAccess',
        'Property',
        'FrontOffice',
        'Housekeeping',
        'Laundry',
        'FnbSales',
        'Kitchen',
        'InventoryPurchasing',
        'Maintenance',
        'Routines',
        'HumanResource',
        'Finance',
        'Reporting',
        'GuestExperience',
    ];

    /** @var list<string> */
    private const MODULE_LAYERS = [
        'Domain',
        'Application',
        'Infrastructure',
        'Presentation',
    ];

    /** @var list<string> */
    private const SHARED_LAYERS = [
        'Domain',
        'Application',
        'Infrastructure',
    ];

    /** @var list<string> */
    private const ROOT_DIRECTORIES = [
        'Http',
        'Modules',
        'Providers',
        'Shared',
    ];

    /** @var list<string> */
    private const FRAMEWORK_PREFIXES = [
        'Illuminate\\',
        'Inertia\\',
        'Laravel\\',
    ];

    /**
     * @return list<string>
     */
    public function inspectApplication(string $appPath): array
    {
        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if ($source === false) {
                $violations[] = sprintf('%s: source file could not be read.', $file->getPathname());

                continue;
            }

            $relativePath = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($file->getPathname(), strlen(rtrim($appPath, DIRECTORY_SEPARATOR)) + 1),
            );

            array_push($violations, ...$this->inspectSource($relativePath, $source));
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /**
     * @return list<string>
     */
    public function inspectSource(string $relativePath, string $source): array
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $segments = explode('/', $relativePath);
        $rootDirectory = $segments[0] ?? '';
        $violations = [];

        if (! in_array($rootDirectory, self::ROOT_DIRECTORIES, true)) {
            $violations[] = sprintf(
                '%s: application PHP must live under Http, Modules, Providers, or Shared.',
                $relativePath,
            );
        }

        $namespace = $this->extractNamespace($source);
        $expectedNamespace = $this->expectedNamespace($relativePath);

        if ($namespace !== $expectedNamespace) {
            $violations[] = sprintf(
                '%s: expected namespace %s, found %s.',
                $relativePath,
                $expectedNamespace,
                $namespace ?? '(none)',
            );
        }

        if ($rootDirectory === 'Modules') {
            array_push(
                $violations,
                ...$this->inspectModuleSource($relativePath, $segments, $source),
            );
        }

        if ($rootDirectory === 'Shared') {
            array_push(
                $violations,
                ...$this->inspectSharedSource($relativePath, $segments, $source),
            );
        }

        sort($violations);

        return array_values(array_unique($violations));
    }

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function inspectModuleSource(string $path, array $segments, string $source): array
    {
        $context = $segments[1] ?? '';
        $layer = $segments[2] ?? '';
        $violations = [];

        if (! in_array($context, self::MODULE_CONTEXTS, true)) {
            $violations[] = sprintf('%s: %s is not an approved bounded context.', $path, $context ?: '(missing)');
        }

        if (! in_array($layer, self::MODULE_LAYERS, true)) {
            $violations[] = sprintf('%s: %s is not an approved module layer.', $path, $layer ?: '(missing)');

            return $violations;
        }

        if (in_array($layer, ['Domain', 'Application'], true) && ! $this->hasStrictTypes($source)) {
            $violations[] = sprintf('%s: %s source must declare strict_types=1.', $path, $layer);
        }

        foreach ($this->extractDependencies($source) as $dependency) {
            $violation = $this->moduleDependencyViolation($context, $layer, $dependency);

            if ($violation !== null) {
                $violations[] = sprintf('%s: %s', $path, $violation);
            }
        }

        return $violations;
    }

    /**
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function inspectSharedSource(string $path, array $segments, string $source): array
    {
        $layer = $segments[1] ?? '';
        $violations = [];

        if (! in_array($layer, self::SHARED_LAYERS, true)) {
            $violations[] = sprintf('%s: %s is not an approved shared layer.', $path, $layer ?: '(missing)');

            return $violations;
        }

        if (in_array($layer, ['Domain', 'Application'], true) && ! $this->hasStrictTypes($source)) {
            $violations[] = sprintf('%s: Shared\\%s source must declare strict_types=1.', $path, $layer);
        }

        foreach ($this->extractDependencies($source) as $dependency) {
            $violation = $this->sharedDependencyViolation($layer, $dependency);

            if ($violation !== null) {
                $violations[] = sprintf('%s: %s', $path, $violation);
            }
        }

        return $violations;
    }

    private function moduleDependencyViolation(string $context, string $layer, string $dependency): ?string
    {
        if (in_array($layer, ['Domain', 'Application'], true) && $this->isFrameworkDependency($dependency)) {
            return sprintf('%s cannot depend on framework namespace %s.', $layer, $dependency);
        }

        $moduleDependency = $this->moduleCoordinates($dependency);
        $sharedLayer = $this->sharedLayer($dependency);

        if (str_starts_with($dependency, 'App\\') && $moduleDependency === null && $sharedLayer === null) {
            return sprintf('%s cannot depend on application root namespace %s.', $layer, $dependency);
        }

        if ($layer === 'Domain') {
            if ($moduleDependency !== null
                && ($moduleDependency['context'] !== $context || $moduleDependency['layer'] !== 'Domain')) {
                return sprintf('Domain cannot depend on %s.', $dependency);
            }

            if ($sharedLayer !== null && $sharedLayer !== 'Domain') {
                return sprintf('Domain cannot depend on Shared\\%s.', $sharedLayer);
            }
        }

        if ($layer === 'Application') {
            if ($moduleDependency !== null
                && in_array($moduleDependency['layer'], ['Infrastructure', 'Presentation'], true)) {
                return sprintf('Application cannot depend on %s.', $dependency);
            }

            if ($moduleDependency !== null
                && $moduleDependency['context'] !== $context
                && $moduleDependency['layer'] !== 'Application') {
                return sprintf('Application cannot depend on another context\'s %s layer: %s.', $moduleDependency['layer'], $dependency);
            }

            if ($sharedLayer === 'Infrastructure') {
                return 'Application cannot depend on Shared\\Infrastructure.';
            }
        }

        if ($layer === 'Infrastructure' && $moduleDependency !== null) {
            if ($moduleDependency['layer'] === 'Presentation') {
                return sprintf('Infrastructure cannot depend on %s.', $dependency);
            }

            if ($moduleDependency['context'] !== $context
                && $moduleDependency['layer'] !== 'Application') {
                return sprintf('Infrastructure may use only another context\'s Application contracts, not %s.', $dependency);
            }
        }

        if ($layer === 'Presentation') {
            if ($moduleDependency !== null
                && ($moduleDependency['context'] !== $context
                    || ! in_array($moduleDependency['layer'], ['Application', 'Presentation'], true))) {
                return sprintf('Presentation cannot depend on %s.', $dependency);
            }

            if ($sharedLayer !== null && $sharedLayer !== 'Application') {
                return sprintf('Presentation cannot depend on Shared\\%s.', $sharedLayer);
            }
        }

        return null;
    }

    private function sharedDependencyViolation(string $layer, string $dependency): ?string
    {
        if (in_array($layer, ['Domain', 'Application'], true) && $this->isFrameworkDependency($dependency)) {
            return sprintf('Shared\\%s cannot depend on framework namespace %s.', $layer, $dependency);
        }

        if ($this->moduleCoordinates($dependency) !== null) {
            return sprintf('Shared\\%s cannot depend on module namespace %s.', $layer, $dependency);
        }

        $sharedLayer = $this->sharedLayer($dependency);

        if ($layer === 'Domain' && $sharedLayer !== null && $sharedLayer !== 'Domain') {
            return sprintf('Shared\\Domain cannot depend on Shared\\%s.', $sharedLayer);
        }

        if ($layer === 'Application' && $sharedLayer === 'Infrastructure') {
            return 'Shared\\Application cannot depend on Shared\\Infrastructure.';
        }

        if (str_starts_with($dependency, 'App\\') && $sharedLayer === null) {
            return sprintf('Shared\\%s cannot depend on application root namespace %s.', $layer, $dependency);
        }

        return null;
    }

    private function isFrameworkDependency(string $dependency): bool
    {
        foreach (self::FRAMEWORK_PREFIXES as $prefix) {
            if (str_starts_with($dependency, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function hasStrictTypes(string $source): bool
    {
        return preg_match(
            '/<\\?php\\s+declare\\s*\\(\\s*strict_types\\s*=\\s*1\\s*\\)\\s*;/i',
            $source,
        ) === 1;
    }

    /**
     * @return array{context: string, layer: string}|null
     */
    private function moduleCoordinates(string $dependency): ?array
    {
        if (preg_match('/^App\\\\Modules\\\\([^\\\\]+)\\\\(Domain|Application|Infrastructure|Presentation)(?:\\\\|$)/', $dependency, $matches) !== 1) {
            return null;
        }

        return [
            'context' => $matches[1],
            'layer' => $matches[2],
        ];
    }

    private function sharedLayer(string $dependency): ?string
    {
        if (preg_match('/^App\\\\Shared\\\\(Domain|Application|Infrastructure)(?:\\\\|$)/', $dependency, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function expectedNamespace(string $relativePath): string
    {
        $directory = str_replace('/', '\\', dirname($relativePath));

        return $directory === '.' ? 'App' : 'App\\'.$directory;
    }

    private function extractNamespace(string $source): ?string
    {
        $tokens = token_get_all($source);
        $collecting = false;
        $namespace = '';

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $collecting = true;

                continue;
            }

            if (! $collecting) {
                continue;
            }

            if ($token === ';' || $token === '{') {
                return trim($namespace, " \t\n\r\0\x0B\\");
            }

            if (is_array($token)
                && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                $namespace .= $token[1];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractDependencies(string $source): array
    {
        $tokens = token_get_all($source);
        $dependencies = [];
        $seenTypeDeclaration = false;
        $collectingUse = false;
        $useExpression = '';

        foreach ($tokens as $token) {
            if (is_array($token)
                && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $seenTypeDeclaration = true;
            }

            if (is_array($token) && $token[0] === T_USE && ! $seenTypeDeclaration) {
                $collectingUse = true;
                $useExpression = '';

                continue;
            }

            if ($collectingUse) {
                if ($token === ';') {
                    array_push($dependencies, ...$this->expandUseExpression($useExpression));
                    $collectingUse = false;

                    continue;
                }

                $useExpression .= is_array($token) ? $token[1] : $token;

                continue;
            }

            if (is_array($token)
                && in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                $dependencies[] = ltrim($token[1], '\\');
            }
        }

        $dependencies = array_filter(
            array_map(static fn (string $dependency): string => trim($dependency), $dependencies),
        );

        sort($dependencies);

        return array_values(array_unique($dependencies));
    }

    /**
     * @return list<string>
     */
    private function expandUseExpression(string $expression): array
    {
        $expression = preg_replace('/^\\s*(function|const)\\s+/i', '', trim($expression)) ?? '';

        if ($expression === '') {
            return [];
        }

        if (str_contains($expression, '{')) {
            [$prefix, $group] = explode('{', $expression, 2);
            $group = rtrim($group, "} \t\n\r\0\x0B");
            $prefix = rtrim(trim($prefix), '\\').'\\';

            return array_values(array_filter(array_map(
                fn (string $dependency): string => $prefix.$this->removeAlias($dependency),
                explode(',', $group),
            )));
        }

        return array_values(array_filter(array_map(
            fn (string $dependency): string => $this->removeAlias($dependency),
            explode(',', $expression),
        )));
    }

    private function removeAlias(string $dependency): string
    {
        $dependency = preg_replace('/\\s+as\\s+[A-Za-z_][A-Za-z0-9_]*\\s*$/i', '', trim($dependency)) ?? '';

        return ltrim(trim($dependency), '\\');
    }
}
