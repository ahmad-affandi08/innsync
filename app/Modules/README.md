# Module source boundary

Only the bounded contexts declared in `docs/ARCHITECTURE/03-BOUNDED-CONTEXTS.md` may live here. PHP source must use this path and namespace shape:

```text
app/Modules/<Context>/<Domain|Application|Infrastructure|Presentation>/...
App\Modules\<Context>\<Domain|Application|Infrastructure|Presentation>\...
```

`tests/Architecture/ArchitectureBoundariesTest.php` enforces the approved context names, namespace/path correspondence, inward dependency direction, and cross-context infrastructure isolation.
