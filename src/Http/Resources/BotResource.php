<?php

namespace RTippin\Messenger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RTippin\Messenger\Models\Bot;

class BotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request $request
     * @return array
     */
    public function toArray($request): array
    {
        /** @var Bot $bot */
        $bot = $this->resource;

        return [
            'id' => $bot->id,
            'thread_id' => $bot->thread_id,
            'owner_id' => $bot->owner_id,
            'owner_type' => $bot->owner_type,
            'owner' => (new ProviderResource($bot->owner))->resolve(),
            'created_at' => $bot->created_at,
            'updated_at' => $bot->updated_at,
            'name' => $bot->getProviderName(),
            'enabled' => $bot->enabled,
            'cooldown' => $bot->cooldown,
            'has_cooldown' => $bot->isOnCooldown(),
            'actions_count' => $this->addValidActionsCount($bot),
            $this->merge($this->addAvatar($bot)),
        ];
    }

    /**
     * @param Bot $bot
     * @return array
     */
    private function addAvatar(Bot $bot): array
    {
        return [
            'api_avatar' => [
                'sm' => $bot->getProviderAvatarRoute('sm', true),
                'md' => $bot->getProviderAvatarRoute('md', true),
                'lg' => $bot->getProviderAvatarRoute('lg', true),
            ],
            'avatar' => [
                'sm' => $bot->getProviderAvatarRoute('sm'),
                'md' => $bot->getProviderAvatarRoute('md'),
                'lg' => $bot->getProviderAvatarRoute('lg'),
            ],
        ];
    }

    /**
     * @param Bot $bot
     * @return int
     */
    private function addValidActionsCount(Bot $bot): int
    {
        return $bot->valid_actions_count ?? $bot->validActions()->count();
    }
}
