#!/bin/sh
# A new version: the tag on GitHub, the package (make release) and - with the GitHub CLI (gh) - the GitHub release
# with the package attached. Run by `make tag version=0.9.2`. Stops before anything is pushed if the working tree
# has changes, HEAD is not master as on GitHub, or the tag exists.
set -e
cd "$(dirname "$0")/.."
VERSION="$1"
REMOTE=origin
BRANCH=master

echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || { echo "Usage: make tag version=<x.y.z> (e.g. 0.9.2, no v in front)"; exit 1; }
test -z "$(git status --porcelain)" || { echo "The working tree has changes - commit or stash them first:"; git status --short; exit 1; }
test "$(git rev-parse --abbrev-ref HEAD)" = "$BRANCH" || { echo "Releases are tagged on $BRANCH - you are on $(git rev-parse --abbrev-ref HEAD)."; exit 1; }
git fetch --quiet --tags "$REMOTE" "$BRANCH"
test "$(git rev-parse HEAD)" = "$(git rev-parse "$REMOTE/$BRANCH")" || { echo "$BRANCH is not the same as on GitHub - push (or pull) first."; exit 1; }
! git rev-parse -q --verify "refs/tags/$VERSION" >/dev/null || { echo "The tag $VERSION exists already."; exit 1; }

# The package first: if the build fails, no tag is pushed
git tag -a "$VERSION" -m "Excellent CMS $VERSION"
if ! make release; then
  git tag -d "$VERSION" >/dev/null
  echo "make release failed - the tag $VERSION was removed again."
  exit 1
fi
PACKAGE="runtime/release/excellent-cms-$VERSION.tar.gz"
test -f "$PACKAGE" || { git tag -d "$VERSION" >/dev/null; echo "$PACKAGE is missing - the tag $VERSION was removed again."; exit 1; }
git push "$REMOTE" "$VERSION"

if command -v gh >/dev/null 2>&1; then
  gh release create "$VERSION" "$PACKAGE" --title "Excellent CMS $VERSION" --generate-notes
else
  echo
  echo "Tag $VERSION is on GitHub. Without the GitHub CLI (brew install gh), create the release by hand:"
  echo "  Releases > Draft a new release > tag $VERSION > attach $PACKAGE"
fi
