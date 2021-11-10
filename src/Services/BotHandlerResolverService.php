<?php

namespace RTippin\Messenger\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RTippin\Messenger\Actions\Bots\BotActionHandler;
use RTippin\Messenger\Exceptions\BotException;
use RTippin\Messenger\MessengerBots;
use RTippin\Messenger\Models\BotAction;

class BotHandlerResolverService
{
    /**
     * @var MessengerBots
     */
    private MessengerBots $bots;

    /**
     * @var BotActionHandler
     */
    private BotActionHandler $handler;

    /**
     * @var array
     */
    private array $handlerSettings;

    /**
     * @param  MessengerBots  $bots
     */
    public function __construct(MessengerBots $bots)
    {
        $this->bots = $bots;
    }

    /**
     * Resolve a bot handler's data used for storing/updating the BotAction model.
     * Validate against our base ruleset and any custom rules or overrides on the
     * handler class itself. The handler alias validation can be bypassed if an
     * action's handler class or alias is supplied.
     *
     * @param  array  $data
     * @param  string|null  $handlerOrAlias
     * @return array
     *
     * @throws ValidationException|BotException
     */
    public function resolve(array $data, ?string $handlerOrAlias = null): array
    {
        // Validate and initialize the handler / alias
        $this->handler = $this->bots->initializeHandler(
            $handlerOrAlias ?: $this->validateHandlerAlias($data)
        );

        // Set the settings the handler defined
        $this->handlerSettings = $this->bots->getActiveHandlerSettings();

        // Generate the overrides for the handler.
        $overrides = $this->getHandlerOverrides();

        // Validate against the handler settings, omitting overrides
        $validated = $this->validateHandlerSettings($data, $overrides);

        // Gather the generated data array from our validated and merged properties
        $generated = $this->generateHandlerDataForStoring($validated, $overrides);

        // Validate the final formatted triggers to ensure it is
        // not empty if our match method was not "MATCH_ANY"
        $this->validateFormattedTriggers($generated);

        return $generated;
    }

    /**
     * @param  array  $data
     * @return string
     *
     * @throws ValidationException
     */
    private function validateHandlerAlias(array $data): string
    {
        return Validator::make($data, [
            'handler' => ['required', Rule::in($this->bots->getAliases())],
        ])->validate()['handler'];
    }

    /**
     * @return array
     */
    private function getHandlerOverrides(): array
    {
        $overrides = [];

        if (! is_null($this->handlerSettings['match'])) {
            $overrides['match'] = $this->handlerSettings['match'];
        }

        if (! is_null($this->handlerSettings['triggers'])
            && $this->handlerSettings['match'] !== MessengerBots::MATCH_ANY) {
            $overrides['triggers'] = BotAction::formatTriggers($this->handlerSettings['triggers']);
        }

        return $overrides;
    }

    /**
     * @param  array  $data
     * @param  array  $overrides
     * @return array
     *
     * @throws ValidationException
     */
    private function validateHandlerSettings(array $data, array $overrides): array
    {
        $mergedRuleset = array_merge([
            'cooldown' => ['required', 'integer', 'between:0,900'],
            'admin_only' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
        ], $this->handler->rules());

        $validator = Validator::make(
            $data,
            $mergedRuleset,
            $this->generateErrorMessages()
        );

        $this->addConditionalHandlerValidations($validator, $overrides);

        return $validator->validate();
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  array  $overrides
     */
    private function addConditionalHandlerValidations(\Illuminate\Validation\Validator $validator, array $overrides): void
    {
        $validator->sometimes('match', [
            'required',
            'string',
            Rule::in($this->bots->getMatchMethods()),
        ], fn () => ! array_key_exists('match', $overrides));

        $validator->sometimes('triggers', [
            'required',
            'array',
            'min:1',
        ], fn (Fluent $input) => $this->shouldValidateTriggers($input, $overrides));

        $validator->sometimes('triggers.*', [
            'required',
            'string',
        ], fn (Fluent $input) => $this->shouldValidateTriggers($input, $overrides));
    }

    /**
     * @param  Fluent  $input
     * @param  array  $overrides
     * @return bool
     */
    private function shouldValidateTriggers(Fluent $input, array $overrides): bool
    {
        return $this->triggersNotInOverrides($overrides)
            && $this->matchMethodIsNotMatchAny($input)
            && $this->matchMethodOverrideIsNotMatchAny($overrides);
    }

    /**
     * @param  array  $overrides
     * @return bool
     */
    private function triggersNotInOverrides(array $overrides): bool
    {
        return ! array_key_exists('triggers', $overrides);
    }

    /**
     * @param  Fluent  $input
     * @return bool
     */
    private function matchMethodIsNotMatchAny(Fluent $input): bool
    {
        return $input->get('match') !== MessengerBots::MATCH_ANY;
    }

    /**
     * @param  array  $overrides
     * @return bool
     */
    private function matchMethodOverrideIsNotMatchAny(array $overrides): bool
    {
        return ! (array_key_exists('match', $overrides)
            && $overrides['match'] === MessengerBots::MATCH_ANY);
    }

    /**
     * @param  array  $data
     * @return void
     *
     * @throws ValidationException
     */
    private function validateFormattedTriggers(array $data): void
    {
        if (! is_null($data['triggers'])) {
            Validator::make($data, [
                'triggers' => ['required', 'string'],
            ])->validate();
        }
    }

    /**
     * Merge our error messages with any custom messages defined on the handler.
     *
     * @return array
     */
    private function generateErrorMessages(): array
    {
        return array_merge([
            'triggers.*.required' => 'Trigger field is required.',
            'triggers.*.string' => 'A trigger must be a string.',
        ], $this->handler->errorMessages());
    }

    /**
     * Make the final data array we will pass to create a new BotAction.
     *
     * @param  array  $data
     * @param  array  $overrides
     * @return array
     */
    private function generateHandlerDataForStoring(array $data, array $overrides): array
    {
        return [
            'handler' => get_class($this->handler),
            'unique' => $this->handlerSettings['unique'],
            'authorize' => $this->handlerSettings['authorize'],
            'name' => $this->handlerSettings['name'],
            'match' => $overrides['match'] ?? $data['match'],
            'triggers' => $this->generateTriggers($data, $overrides),
            'admin_only' => $data['admin_only'],
            'cooldown' => $data['cooldown'],
            'enabled' => $data['enabled'],
            'payload' => $this->generatePayload($data),
        ];
    }

    /**
     * Strip any non-base rules from the array, then call to the handlers
     * serialize to json encode our payload.
     *
     * @param  array  $data
     * @return string|null
     */
    private function generatePayload(array $data): ?string
    {
        $ruleKeys = [
            'match',
            'cooldown',
            'admin_only',
            'enabled',
            'triggers',
            'triggers.*',
        ];

        $payload = (new Collection($data))
            ->reject(fn ($value, $key) => in_array($key, $ruleKeys))
            ->toArray();

        if (count($payload)) {
            return $this->handler->serializePayload($payload);
        }

        return null;
    }

    /**
     * @param  array  $data
     * @param  array  $overrides
     * @return string|null
     */
    private function generateTriggers(array $data, array $overrides): ?string
    {
        $match = $overrides['match'] ?? $data['match'];

        if ($match === MessengerBots::MATCH_ANY) {
            return null;
        }

        return $overrides['triggers'] ?? BotAction::formatTriggers($data['triggers']);
    }
}
