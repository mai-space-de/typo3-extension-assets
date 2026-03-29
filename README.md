# maispace/mai-assets — TYPO3 Extension
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net/)
[![TYPO3](https://img.shields.io/badge/TYPO3-13.4%20LTS-orange)](https://typo3.org/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)

The canonical asset pipeline for the entire extension set. Provides Fluid ViewHelper-based asset inclusion with minification, SCSS compilation, and SVG sprite building. Also manages the TYPO3 file abstraction layer via `cms-filelist` and `cms-filemetadata`. All other extensions that need SCSS compilation or asset minification depend on this extension rather than pulling in `scssphp` or minification libraries directly.

**Requires:** TYPO3 13.4 LTS / 14.0 · PHP 8.2+

---

## Installation

```bash
composer require maispace/mai-assets
```

---

## Development

### Linting

```bash
composer lint:check     # Run all linters
composer lint:fix       # Fix auto-fixable issues
```

### Testing

```bash
composer test           # Run all tests
composer test:unit      # Run unit tests only
```

---

## License

GPL-2.0-or-later — see [LICENSE](../../LICENSE) for details.
