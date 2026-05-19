# Contributing

Thanks for your interest in improving HUB RunCloud VPS Stats!

## Reporting bugs

Open an issue at https://github.com/collectifweb/HUB_RC-VPS/issues and include:

- WHMCS version
- PHP version
- Module version (see `hub_rc_vps.php`)
- Whether the VPS is managed by RunCloud or another control panel
- Exact error message (if any) and steps to reproduce

Do **not** paste SSH keys, passwords, or `tbladdonmodules` rows verbatim in issues.

## Pull requests

1. Fork the repo and create a feature branch off `main`.
2. Keep changes focused — one logical change per PR.
3. Follow the existing code style (PSR-12, 4-space indent, no trailing whitespace).
4. Test against a real WHMCS install — there is no automated test suite.
5. Update `README.md` if you change behavior or configuration.

### Commit messages

We use [Conventional Commits](https://www.conventionalcommits.org/):

- `feat:` new feature
- `fix:` bug fix
- `security:` security-relevant change
- `refactor:` non-behavior code change
- `docs:` documentation only

Do not add AI-generated co-author trailers.

## Security disclosures

For security issues, **do not open a public issue**. Email security@collectif-hub.ca instead. We aim to acknowledge reports within 72 hours.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).
