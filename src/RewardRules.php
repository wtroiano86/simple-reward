<?php

declare(strict_types=1);

namespace Wtroiano\SimpleReward;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Wtroiano\SimpleReward\Contracts\Rewardable;
use Wtroiano\SimpleReward\Contracts\RewardRule;
use Wtroiano\SimpleReward\Models\Reward;
use Wtroiano\SimpleReward\Rules\RuleDefinition;

final readonly class RewardRules
{
    public function __construct(private Repository $config, private Container $container) {}

    /**
     * @param  array<string, mixed>  $context
     * @return list<Reward> Newly created credits, in configuration order.
     */
    public function apply(Model&Rewardable $owner, string $event, string $sourceId, array $context = []): array
    {
        throw_if(mb_trim($event) === '' || mb_strlen($event) > 255 || mb_trim($sourceId) === '' || mb_strlen($sourceId) > 255, InvalidArgumentException::class, 'Event names and source IDs must contain 1-255 characters.');
        throw_unless($owner->exists, LogicException::class, 'Save the model before applying reward rules.');
        $ownerId = $owner->getKey();
        throw_unless(is_int($ownerId) || is_string($ownerId), LogicException::class, 'Reward owners require an integer or string key.');

        $rules = $this->rules();

        return $owner->getConnection()->transaction(function () use ($owner, $ownerId, $event, $sourceId, $context, $rules): array {
            // Serialize duplicate checks and credits even for the first award.
            $owner->newQueryWithoutScopes()->whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $eligibleRules = [];

            foreach ($rules as $rule) {
                if (! $rule->matches($event, $context)) {
                    continue;
                }

                // Version and event are intentionally excluded: a source earns a rule once.
                $identity = hash('sha256', json_encode([$owner->getMorphClass(), (string) $ownerId, $rule->key, $sourceId], JSON_THROW_ON_ERROR));
                $eligibleRules[$identity] = $rule;
            }

            if ($eligibleRules === []) {
                return [];
            }

            $applied = Reward::on($owner->getConnection()->getName())
                ->whereIn('rule_application_key', array_keys($eligibleRules))
                ->lockForUpdate()
                ->get(['rule_application_key'])
                ->keyBy('rule_application_key');
            $entries = [];

            foreach ($eligibleRules as $identity => $rule) {
                if ($applied->has($identity)) {
                    continue;
                }

                $entries[] = $owner->rewardCredit($rule->award->amount, $rule->award->description, [
                    'rule' => ['key' => $rule->key, 'version' => $rule->version],
                    'source' => ['event' => $event, 'id' => $sourceId],
                ], ruleApplicationKey: $identity);
            }

            return $entries;
        }, 5);
    }

    /** @return list<RuleDefinition> */
    private function rules(): array
    {
        $classes = $this->config->get('simple-reward.rules', []);
        throw_unless(is_array($classes) && array_is_list($classes), InvalidArgumentException::class, 'Reward rules must be a list of rule class names.');
        $rules = [];
        $keys = [];

        foreach ($classes as $class) {
            throw_unless(is_string($class) && is_subclass_of($class, RewardRule::class), InvalidArgumentException::class, 'Each reward rule must name a class implementing the RewardRule contract.');
            $instance = $this->container->make($class);
            throw_unless($instance instanceof RewardRule, InvalidArgumentException::class, 'Resolved reward rules must implement the RewardRule contract.');
            $rule = $instance->definition();
            throw_if(in_array($rule->key, $keys, true), InvalidArgumentException::class, 'Reward rule keys must be unique.');
            $keys[] = $rule->key;
            $rules[] = $rule;
        }

        return $rules;
    }
}
