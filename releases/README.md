# Release Notes

Add a file named `vX.Y.Z.md` for each release before tagging it.

Example: `releases/v0.1.0.md`.

## Releasing

1. Merge the release into `main` through a pull request (CI must be green).
2. Add `releases/vX.Y.Z.md` in that pull request.
3. Tag the merged `main` commit and push the tag:

   ```bash
   git checkout main && git pull --ff-only
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   ```

4. The `Release` workflow verifies the tag (semantic version, commit on `main`,
   notes present, no `version` in `composer.json`, no existing release), reruns
   tests and PHPStan on that revision and publishes the GitHub release.
5. Packagist picks the tag up through its GitHub integration.

Never move or delete a published tag; release a new patch version instead.
