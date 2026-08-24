/**
 * Configuration for the release workflow (.github/workflows/release.yml).
 * Conventional Commits on main → automatic semver tags + CHANGELOG.
 *
 * @type {import('semantic-release').GlobalConfig}
 */
module.exports = {
  branches: ["main"],
  plugins: [
    ["@semantic-release/commit-analyzer", {
      preset: "conventionalcommits",
      releaseRules: [
        { type: "feat", release: "minor" },
        { type: "fix", release: "patch" },
        { type: "perf", release: "patch" },
        { type: "chore", release: "patch" },
        { type: "ci", release: "patch" },
        { type: "docs", release: "patch" },
        { type: "refactor", release: "patch" },
        { type: "style", release: "patch" },
        { type: "test", release: "patch" },
        { type: "build", release: "patch" },
      ],
    }],
    ["@semantic-release/release-notes-generator", {
      preset: "conventionalcommits",
    }],
    ["@semantic-release/github", {
      successCommentCondition: false,
      failCommentCondition: false,
    }],
    "@semantic-release/changelog",
    ["@semantic-release/exec", {
      generateNotesCmd: "echo -n ${nextRelease.version} > .gitrelease && echo -n ${nextRelease.version} > VERSION",
    }],
    ["@semantic-release/git", {
      assets: ["CHANGELOG.md", "VERSION"],
      message: "chore(release): ${nextRelease.version} [skip ci]\n\n${nextRelease.notes}",
    }],
  ],
};
