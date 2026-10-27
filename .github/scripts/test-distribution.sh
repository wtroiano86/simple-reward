#!/bin/sh
set -eu

directory=$(mktemp -d)
trap 'rm -rf "$directory"' EXIT HUP INT TERM
composer archive --format=zip --dir="$directory" --file=package --no-interaction
php -r '
$archive = new ZipArchive;
if ($archive->open($argv[1]) !== true) {
    fwrite(STDERR, "Unable to open distribution archive.\n");
    exit(1);
}
for ($index = 0; $index < $archive->numFiles; $index++) {
    echo $archive->getNameIndex($index), "\n";
}
$archive->close();
' "$directory/package.zip" > "$directory/files"
for required in composer.json LICENSE NOTICE.md README.md CHANGELOG.md CONTRIBUTING.md llms.txt src/Traits/HasRewards.php src/Models/Reward.php src/RewardRules.php src/Contracts/Rewardable.php src/Contracts/RewardRule.php src/Rules/RuleDefinition.php src/Rules/AllOf.php src/Rules/Condition.php src/Rules/FixedAward.php src/Rules/Operator.php src/Providers/RewardServiceProvider.php src/Events/RewardsCredited.php src/Events/RewardsDebited.php src/Events/RewardsTransferred.php src/Exceptions/InsufficientRewardException.php config/simple-reward.php database/migrations/create_rewards_table.php.stub resources/boost/guidelines/core.blade.php resources/boost/skills/simple-reward-development/SKILL.md docs/README.md docs/architecture.md docs/boost.md docs/consumers/getting-started.md docs/consumers/rewards.md docs/consumers/rules.md docs/consumers/metadata.md docs/development/contributing.md docs/development/testing.md docs/development/releasing.md docs/development/agent-workflow.md; do
    if ! grep -Fxq "$required" "$directory/files"; then
        echo "Missing distribution file: $required" >&2
        exit 1
    fi
done
if grep -Eq '(^|/)(\._[^/]*|\.DS_Store)$|^(vendor/|tests/|\.github/|\.idea/|\.ijfw/|ijfw/|\.planning/|tmp/|temp/|\.phpstan/|\.phpunit.cache/|AGENTS\.md$|composer\.lock$|composer\.local\.(json|lock)$|phpunit\.xml$|phpstan\.neon$|pint\.json$|rector\.php$|\.editorconfig$|\.gitattributes$|\.gitignore$)' "$directory/files"; then
    echo 'Development files leaked into the distribution.' >&2
    exit 1
fi
echo 'Distribution includes runtime, documentation and Boost resources and excludes development files.'
