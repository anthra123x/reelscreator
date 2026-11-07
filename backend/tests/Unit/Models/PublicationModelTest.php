<?php

namespace Tests\Unit\Models;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Models\Publication;
use App\Modules\SocialAccounts\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicationModelTest extends TestCase
{
    public function test_publication_attributes_and_statuses(): void
    {
        $pub = new Publication([
            'clip_id' => (string) Str::uuid(),
            'social_account_id' => (string) Str::uuid(),
            'status' => Publication::STATUS_PENDING,
            'scheduled_at' => Carbon::now()->addHour(),
        ]);
        $pub->id = (string) Str::uuid();

        $this->assertTrue(Str::isUuid($pub->id));
        $this->assertEquals(Publication::STATUS_PENDING, $pub->status);
        $this->assertInstanceOf(Carbon::class, $pub->scheduled_at);
        $this->assertContains($pub->status, Publication::ALLOWED_STATUSES);
    }

    public function test_relationships_definition(): void
    {
        $pub = new Publication;
        $this->assertInstanceOf(BelongsTo::class, $pub->clip());
        $this->assertInstanceOf(Clip::class, $pub->clip()->getRelated());

        $this->assertInstanceOf(BelongsTo::class, $pub->socialAccount());
        $this->assertInstanceOf(SocialAccount::class, $pub->socialAccount()->getRelated());
    }
}
