# Contributing to Stochastix

First off, thank you for considering contributing to Stochastix! It's people like you that make open source such a great community.

## How Can I Contribute?

### Reporting Bugs

If you find a bug, please ensure the bug was not already reported by searching on GitHub under [Issues](https://github.com/phpquant/stochastix-core/issues).

If you're unable to find an open issue addressing the problem, [open a new one](https://github.com/phpquant/stochastix-core/issues/new). Be sure to include a **title and clear description**, as much relevant information as possible, and a **code sample or an executable test case** demonstrating the expected behavior that is not occurring.

### Suggesting Enhancements

Enhancement suggestions are tracked as [GitHub issues](https://github.com/phpquant/stochastix-core/issues). Create an issue on that repository and describe the enhancement you would like to see, why it would be useful, and any implementation ideas you may have.

### Pull Request Process

1.  Fork the repository and create your branch from `master`.
2.  Ensure the test suite passes with your changes. Run `make test`.
3.  If you've added code that should be tested, add new tests.
4.  Make sure your code lints. We use `php-cs-fixer` and `phpstan`. Run `make fix` and `make stan`.
5.  Issue that pull request!

## Coding Standards

This project follows the **PER-CS2.0** coding standard. To automatically format your code before committing, you can run:

```bash
make fix
```
