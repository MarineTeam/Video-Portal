<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Auth\Capability;
use Portal\Auth\PermissionSeeder;
use Portal\Auth\UserRepository;
use Portal\Content\CategoryRepository;
use Portal\Content\VideoRepository;
use Portal\Sharing\PrivateList;
use Portal\Sharing\Share;
use Portal\Sharing\ShareRepository;
use Portal\Sharing\ViewerGroups;

/**
 * Private lists and viewer groups.
 *
 * The two properties worth defending:
 *
 *   A private list is its OWN index, not a query over live shares. Removing
 *   someone revokes exactly the share the list created and leaves any ordinary
 *   share to the same person for the same video alone. The alternative — deriving
 *   membership from active shares — would silently drop people whose link lapsed
 *   and would guess wrong about which share to revoke.
 *
 *   A viewer group grants nothing. It composes a recipient list and no more, so
 *   deleting a group cannot take away access that was already sent.
 */
final class PrivateListTest extends DatabaseTestCase
{
    private PrivateList $lists;
    private ShareRepository $shares;
    private ViewerGroups $groups;
    private VideoRepository $videos;
    private int $videoId;

    protected function setUp(): void
    {
        $this->truncate([
            'private_list_entries', 'viewer_group_members', 'viewer_groups', 'user_tags',
            'bundle_items', 'bundles', 'shares', 'video_categories', 'videos', 'categories',
            'grants', 'group_members', 'role_capabilities', 'capabilities', 'roles', 'users',
        ]);

        (new PermissionSeeder($this->db()))->seed();

        $categories = new CategoryRepository($this->db());
        $this->videos = new VideoRepository($this->db(), $categories);
        $this->shares = new ShareRepository($this->db(), $this->videos);
        $this->lists = new PrivateList($this->db(), $this->shares);
        $this->groups = new ViewerGroups($this->db());

        $this->videoId = $this->makeVideo('A Sermon');
    }

    private function makeVideo(string $title): int
    {
        $now = date('Y-m-d H:i:s');

        return $this->db()->insert('videos', [
            'provider'     => 'bunny',
            'provider_id'  => bin2hex(random_bytes(8)),
            'slug'         => $this->videos->uniqueSlug($title),
            'title'        => $title,
            'status'       => 'ready',
            'is_published' => 1,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    // -------------------------------------------------------- private lists

    public function testAddingPeopleCreatesSharesForThem(): void
    {
        $result = $this->lists->add($this->videoId, ['a@example.test', 'b@example.test']);

        self::assertCount(2, $result['added']);
        self::assertSame(2, $this->lists->count($this->videoId));

        foreach ($result['added'] as $share) {
            self::assertTrue($share->viaPrivateList, 'The share should be marked as coming from the list.');
            self::assertTrue($share->isLive());
        }
    }

    /**
     * Adding a name twice is a slip, not a request for a second link — and a
     * second link would mean a second, confusing invitation email.
     */
    public function testAddingSomeoneAlreadyOnTheListIsANoOp(): void
    {
        $this->lists->add($this->videoId, ['a@example.test']);

        $again = $this->lists->add($this->videoId, ['a@example.test']);

        self::assertCount(0, $again['added'], 'No second share should be minted.');
        self::assertSame(['a@example.test'], $again['skipped']);
        self::assertSame(1, $this->lists->count($this->videoId));
    }

    public function testRemovingRevokesTheShareTheListCreated(): void
    {
        $result = $this->lists->add($this->videoId, ['a@example.test']);
        $share = $result['added'][0];

        self::assertTrue($this->lists->remove($this->videoId, 'a@example.test'));

        self::assertFalse($this->lists->has($this->videoId, 'a@example.test'));
        self::assertTrue($this->shares->find($share->id)?->isRevoked());
    }

    /**
     * The deliberate, documented trade-off. The list revokes only what it
     * made; a share someone else granted is not the list's to cancel.
     */
    public function testRemovingDoesNotTouchAnOrdinaryShareToTheSamePerson(): void
    {
        $listResult = $this->lists->add($this->videoId, ['a@example.test']);
        $listShare = $listResult['added'][0];

        // An ad-hoc share to the same person for the same video.
        $adHoc = $this->shares->create($this->videoId, 'a@example.test');

        $this->lists->remove($this->videoId, 'a@example.test');

        self::assertTrue($this->shares->find($listShare->id)?->isRevoked());
        self::assertTrue(
            $this->shares->find($adHoc->id)?->isLive(),
            'A share the list did not create must survive removal from the list.'
        );
    }

    /** And the reverse: the list ignores shares it did not create. */
    public function testAnOrdinaryShareDoesNotPutSomeoneOnTheList(): void
    {
        $this->shares->create($this->videoId, 'a@example.test');

        self::assertFalse($this->lists->has($this->videoId, 'a@example.test'));
        self::assertSame(0, $this->lists->count($this->videoId));
    }

    /**
     * Membership is a row, not a derived fact, so an expired link leaves
     * someone visibly on the list rather than silently dropping them.
     */
    public function testSomeoneStaysOnTheListWhenTheirLinkExpires(): void
    {
        $result = $this->lists->add($this->videoId, ['a@example.test']);

        $this->db()->execute(
            'UPDATE {shares} SET expires_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id = ?',
            [$result['added'][0]->id]
        );

        self::assertTrue($this->lists->has($this->videoId, 'a@example.test'));

        $members = $this->lists->members($this->videoId);
        self::assertCount(1, $members);
        self::assertFalse(
            $members[0]['share']?->isLive(),
            'The list should show them as on it but no longer able to watch.'
        );
    }

    public function testReAddingAfterRemovalMintsAFreshShare(): void
    {
        $first = $this->lists->add($this->videoId, ['a@example.test'])['added'][0];
        $this->lists->remove($this->videoId, 'a@example.test');

        $second = $this->lists->add($this->videoId, ['a@example.test'])['added'][0];

        self::assertNotSame($first->id, $second->id, 'Re-adding is a new invitation, not a revival.');
        self::assertTrue($second->isLive());
        self::assertTrue($this->shares->find($first->id)?->isRevoked());
    }

    public function testListsAreScopedToOneVideo(): void
    {
        $other = $this->makeVideo('Another Sermon');

        $this->lists->add($this->videoId, ['a@example.test']);

        self::assertTrue($this->lists->has($this->videoId, 'a@example.test'));
        self::assertFalse($this->lists->has($other, 'a@example.test'));
    }

    public function testInvalidAddressesAreReportedNotAdded(): void
    {
        $result = $this->lists->add($this->videoId, ['good@example.test', 'nope', 'also@example.test']);

        self::assertCount(2, $result['added']);
        self::assertArrayHasKey('nope', $result['failed']);
    }

    public function testTheListIsBounded(): void
    {
        $emails = [];
        for ($i = 0; $i < PrivateList::MAX_EMAILS + 10; $i++) {
            $emails[] = "person{$i}@example.test";
        }

        $result = $this->lists->add($this->videoId, $emails);

        self::assertCount(PrivateList::MAX_EMAILS, $result['added']);
        self::assertNotEmpty($result['failed']);
    }

    public function testMembershipLookupIsCaseInsensitive(): void
    {
        $this->lists->add($this->videoId, ['Person@Example.TEST']);

        self::assertTrue($this->lists->has($this->videoId, 'person@example.test'));
        self::assertTrue($this->lists->has($this->videoId, '  PERSON@EXAMPLE.TEST '));
    }

    // -------------------------------------------------------- viewer groups

    public function testGroupsCollectAddresses(): void
    {
        $id = $this->groups->create('Elders');
        $this->groups->addMembers($id, ['a@example.test', 'b@example.test']);

        self::assertSame(['a@example.test', 'b@example.test'], $this->groups->emails($id));
    }

    /** A group is a convenience for composing a list, nothing more. */
    public function testAddingSomeoneToAGroupGrantsNothing(): void
    {
        $id = $this->groups->create('Elders');
        $this->groups->addMembers($id, ['a@example.test']);

        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {shares}'),
            'Group membership must not create access.'
        );
    }

    /** And the links it helped compose belong to the people, not the group. */
    public function testDeletingAGroupDoesNotRevokeWhatWasSent(): void
    {
        $id = $this->groups->create('Elders');
        $this->groups->addMembers($id, ['a@example.test', 'b@example.test']);

        $recipients = $this->groups->expand([$id]);
        $result = $this->shares->createBulk([$this->videoId], $recipients);

        self::assertCount(2, $result['created']);

        $this->groups->delete($id);

        foreach ($result['created'] as $share) {
            self::assertTrue(
                $this->shares->find($share->id)?->isLive(),
                'Deleting a group must not take away access already sent.'
            );
        }
    }

    public function testRemovingAGroupMemberDoesNotRevokeTheirShare(): void
    {
        $id = $this->groups->create('Elders');
        $this->groups->addMembers($id, ['a@example.test']);

        $share = $this->shares->create($this->videoId, 'a@example.test');

        $this->groups->removeMember($id, 'a@example.test');

        self::assertTrue($this->shares->find($share->id)?->isLive());
    }

    public function testExpandingSeveralGroupsDeduplicates(): void
    {
        $first = $this->groups->create('Elders');
        $second = $this->groups->create('Staff');

        $this->groups->addMembers($first, ['shared@example.test', 'a@example.test']);
        $this->groups->addMembers($second, ['shared@example.test', 'b@example.test']);

        $emails = $this->groups->expand([$first, $second]);

        self::assertCount(3, $emails);
        self::assertContains('shared@example.test', $emails);
    }

    public function testGroupSlugsDoNotCollide(): void
    {
        $first = $this->groups->create('Elders');
        $second = $this->groups->create('Elders');

        self::assertNotSame($first, $second);
        self::assertCount(2, $this->groups->all());
    }

    // ------------------------------------------------------------------ tags

    public function testTagsLabelViewers(): void
    {
        $users = new UserRepository($this->db());
        $user = $users->create('tagged@example.test', null, Capability::ROLE_VIEWER, null, true);

        $this->groups->setTags($user->id, ['elders', 'staff']);

        self::assertSame(['elders', 'staff'], $this->groups->tagsFor($user->id));
    }

    public function testSettingTagsReplacesRatherThanAppends(): void
    {
        $users = new UserRepository($this->db());
        $user = $users->create('tagged@example.test', null, Capability::ROLE_VIEWER, null, true);

        $this->groups->setTags($user->id, ['elders', 'staff']);
        $this->groups->setTags($user->id, ['volunteers']);

        self::assertSame(['volunteers'], $this->groups->tagsFor($user->id));
    }

    /**
     * A link sent to an unapproved account lands on "your account is not
     * approved yet", which is confusing for them and for whoever sent it.
     */
    public function testTagLookupSkipsUnapprovedAccounts(): void
    {
        $users = new UserRepository($this->db());

        $approved = $users->create('approved@example.test', null, Capability::ROLE_VIEWER, null, true);
        $pending = $users->create('pending@example.test', null, Capability::ROLE_VIEWER, null, false);

        $this->groups->setTags($approved->id, ['elders']);
        $this->groups->setTags($pending->id, ['elders']);

        self::assertSame(['approved@example.test'], $this->groups->emailsForTag('elders'));
    }

    public function testTagsPerUserAreBounded(): void
    {
        $users = new UserRepository($this->db());
        $user = $users->create('tagged@example.test', null, Capability::ROLE_VIEWER, null, true);

        $tags = [];
        for ($i = 0; $i < ViewerGroups::MAX_TAGS_PER_USER + 10; $i++) {
            $tags[] = "tag{$i}";
        }

        $this->groups->setTags($user->id, $tags);

        self::assertLessThanOrEqual(ViewerGroups::MAX_TAGS_PER_USER, count($this->groups->tagsFor($user->id)));
    }

    // ------------------------------------------------------------- resolving

    /**
     * Overlapping selections must not produce two links for one person.
     */
    public function testResolvingCombinesEverythingAndDeduplicates(): void
    {
        $users = new UserRepository($this->db());
        $user = $users->create('tagged@example.test', null, Capability::ROLE_VIEWER, null, true);
        $this->groups->setTags($user->id, ['elders']);

        $group = $this->groups->create('Staff');
        $this->groups->addMembers($group, ['grouped@example.test', 'tagged@example.test']);

        $result = $this->groups->resolveRecipients(
            ['typed@example.test, tagged@example.test'],
            [$group],
            ['elders']
        );

        sort($result['valid']);

        self::assertSame(
            ['grouped@example.test', 'tagged@example.test', 'typed@example.test'],
            $result['valid']
        );
    }

    public function testResolvingReportsTypedNonsense(): void
    {
        $result = $this->groups->resolveRecipients(['good@example.test, rubbish, also@example.test']);

        self::assertCount(2, $result['valid']);
        self::assertSame(['rubbish'], $result['invalid']);
    }
}
