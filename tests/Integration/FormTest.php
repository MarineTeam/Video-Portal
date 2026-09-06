<?php

declare(strict_types=1);

namespace Portal\Tests\Integration;

use Portal\Forms\FieldType;
use Portal\Forms\FormExport;
use Portal\Forms\FormRepository;
use Portal\Forms\Submission;
use Portal\Http\HttpException;

/**
 * What a form remembers, and what it refuses to rewrite.
 *
 * Against a real database because the rule is about rows surviving a change of
 * words — and because the strongest half of it is a foreign key, which no mock
 * can enforce and no unit test can see.
 */
final class FormTest extends DatabaseTestCase
{
    private FormRepository $forms;
    private int $formId;

    protected function setUp(): void
    {
        $this->truncate(['form_answers', 'form_responses', 'form_questions', 'forms', 'users']);

        $this->forms = new FormRepository($this->db());
        $this->formId = $this->forms->create('Connect card');
    }

    /** @param list<string> $options */
    private function ask(
        string $label,
        string $type = FieldType::TEXT,
        array $options = [],
        bool $required = false
    ): int {
        return $this->forms->addQuestion($this->formId, $label, $type, $options, $required);
    }

    /** @param array<string, mixed> $input */
    private function send(array $input): int
    {
        $read = Submission::read($this->forms->questions($this->formId), $input);

        self::assertTrue($read['ok'], 'the fixture sent something the form refused');

        return $this->forms->store($this->formId, $read['answers'], null, $read['name'], $read['email']);
    }

    // ------------------------------- an answer belongs to the question

    /**
     * THE RULE. Renaming a question must not rewrite history — March's
     * responses still say what they were answering.
     */
    public function testRenamingAQuestionDoesNotChangeWhatWasAnswered(): void
    {
        $phone = $this->ask('Phone');
        $responseId = $this->send(['q' . $phone => '01234 567890']);

        $this->forms->renameQuestion($phone, 'Mobile number');

        self::assertSame(
            ['01234 567890'],
            array_values($this->forms->answersFor($responseId)),
            'the answer moved when the words did'
        );
    }

    /**
     * A retired question keeps its answers, and stops being asked.
     *
     * Both halves matter: the answers have to survive, or a term's responses
     * quietly change meaning; and the question has to disappear from the form,
     * or retiring it did nothing.
     */
    public function testARetiredQuestionKeepsItsAnswersAndStopsBeingAsked(): void
    {
        $course = $this->ask('Which course?');
        $responseId = $this->send(['q' . $course => 'Alpha']);

        $this->forms->retireQuestion($course);

        self::assertSame([], $this->forms->questions($this->formId), 'it is still being asked');
        self::assertSame(['Alpha'], array_values($this->forms->answersFor($responseId)));
        self::assertCount(1, $this->forms->allQuestions($this->formId), 'the question itself vanished');
    }

    /**
     * And the database refuses to delete an answered question at all.
     *
     * The strongest form of the rule, and the reason it is a foreign key rather
     * than a code path: nothing in the application offers deletion today, but a
     * constraint holds against the second code path somebody adds.
     */
    public function testAnAnsweredQuestionCannotBeDeleted(): void
    {
        $question = $this->ask('Which course?');
        $this->send(['q' . $question => 'Alpha']);

        $this->expectException(\Throwable::class);

        $this->db()->execute('DELETE FROM {form_questions} WHERE id = ?', [$question]);
    }

    /** An unasked question is deletable, so the constraint is not just "never". */
    public function testAnUnansweredQuestionCanStillBeDeleted(): void
    {
        $question = $this->ask('A mistake');

        $this->db()->execute('DELETE FROM {form_questions} WHERE id = ?', [$question]);

        self::assertSame([], $this->forms->questions($this->formId));
    }

    // --------------------------------------- the server has the last word

    /**
     * A crafted submission cannot invent a fourth answer to a three-way
     * question, whatever the page it claims to come from said.
     */
    public function testAnInventedChoiceIsRefusedAndNothingIsStored(): void
    {
        $service = $this->ask('Which service?', FieldType::CHOICE, ['9am', '11am', 'Evening'], true);

        $read = Submission::read($this->forms->questions($this->formId), ['q' . $service => 'Midnight']);

        self::assertFalse($read['ok']);
        self::assertArrayHasKey($service, $read['errors']);
        self::assertSame(
            0,
            (int) $this->db()->value('SELECT COUNT(*) FROM {form_answers}'),
            'a refused submission wrote something'
        );
    }

    /**
     * A submission carrying a field this form never asked contributes nothing.
     *
     * The loop is over the questions, never over the input — so there is no
     * path that stores a value the form did not ask for, including a question
     * belonging to another form entirely.
     */
    public function testAFieldTheFormNeverAskedIsIgnored(): void
    {
        $mine = $this->ask('Name');

        $otherForm = $this->forms->create('Something else');
        $theirs = $this->forms->addQuestion($otherForm, 'Their question', FieldType::TEXT);

        $responseId = $this->send([
            'q' . $mine   => 'Jane Cole',
            'q' . $theirs => 'smuggled',
            'q999999'     => 'invented',
        ]);

        $stored = $this->forms->answersFor($responseId);

        self::assertSame([$mine => 'Jane Cole'], $stored, 'a question this form does not ask was answered');
    }

    /** A retired question is not answerable either, even by an old page. */
    public function testARetiredQuestionCannotBeAnsweredByAStalePage(): void
    {
        $old = $this->ask('Last term');
        $this->forms->retireQuestion($old);
        $now = $this->ask('This term');

        $responseId = $this->send(['q' . $old => 'stale', 'q' . $now => 'fresh']);

        self::assertSame([$now => 'fresh'], $this->forms->answersFor($responseId));
    }

    /**
     * An unanswered optional question stores no row, so "not asked" and "left
     * blank" do not become the same thing in a spreadsheet.
     */
    public function testALeftBlankOptionalQuestionStoresNothing(): void
    {
        $this->ask('Anything else?');
        $responseId = $this->send([]);

        self::assertSame([], $this->forms->answersFor($responseId));
    }

    /** A choice question with nothing to choose is refused at the form builder. */
    public function testAChoiceQuestionNeedsSomethingToChoose(): void
    {
        $this->expectException(HttpException::class);

        $this->forms->addQuestion($this->formId, 'Which?', FieldType::CHOICE, []);
    }

    // ------------------------------------------------------ dealt with

    /**
     * BY NAME. The way follow-up fails is two people each assuming the other
     * rang, and a tick with no name beside it produces exactly that.
     */
    public function testDealingWithAResponseRecordsWho(): void
    {
        $this->ask('Name');
        $responseId = $this->send(['q' . $this->forms->questions($this->formId)[0]['id'] => 'Jane']);

        self::assertSame(1, $this->forms->outstandingCount($this->formId));

        $this->forms->markHandled($responseId, 'Sam Ives', 'Rang Tuesday.');

        $response = (array) $this->forms->response($responseId);

        self::assertSame('Sam Ives', $response['handled_by']);
        self::assertNotNull($response['handled_at']);
        self::assertSame(0, $this->forms->outstandingCount($this->formId));
    }

    public function testDealtWithByNobodyIsRefused(): void
    {
        $this->ask('Name');
        $responseId = $this->send([]);

        $this->expectException(HttpException::class);

        $this->forms->markHandled($responseId, '   ');
    }

    // ------------------------------------------------------ the export

    /**
     * Retired questions come AFTER the live ones, and say so.
     *
     * Left out, a column of real answers disappears from the only export they
     * will ever be in. Mixed in, the sheet stops looking like the form does
     * today and whoever opens it cannot tell which columns are still asked.
     */
    public function testRetiredColumnsComeLastAndAreLabelled(): void
    {
        $old = $this->ask('Last term');
        $responseId = $this->send(['q' . $old => 'Alpha']);
        $this->forms->retireQuestion($old);

        $now = $this->ask('This term');

        $questions = $this->forms->allQuestions($this->formId);
        $csv = FormExport::csv(
            $questions,
            $this->forms->responses($this->formId),
            $this->forms->answersForMany([$responseId])
        );

        $heading = explode("\n", $csv)[0];

        self::assertStringContainsString('This term', $heading);
        self::assertStringContainsString('Last term (no longer asked)', $heading);
        self::assertGreaterThan(
            strpos($heading, 'This term'),
            strpos($heading, 'Last term'),
            'the retired column came before a live one'
        );
        self::assertStringContainsString('Alpha', $csv, 'the retired answers were dropped');
        self::assertSame((int) $now, (int) $questions[0]['id']);
    }

    /**
     * Every cell is text a stranger typed into a public form, and the file is
     * opened in Excel by whoever does the follow-up.
     */
    public function testAFormulaTypedIntoAFormIsDefusedInTheSpreadsheet(): void
    {
        $question = $this->ask('Anything else?');
        $responseId = $this->send(['q' . $question => '=cmd|/c calc']);

        $csv = FormExport::csv(
            $this->forms->allQuestions($this->formId),
            $this->forms->responses($this->formId),
            $this->forms->answersForMany([$responseId])
        );

        self::assertStringNotContainsString(',=cmd', $csv, 'A FORMULA REACHED THE SPREADSHEET');
    }

    // ------------------------------------------------------ visibility

    /**
     * A members-only form is INVISIBLE to a stranger rather than refused. The
     * title is a leak too — a refusal announces there is something to refuse.
     */
    public function testAMembersOnlyFormIsAbsentFromAStrangersList(): void
    {
        $this->forms->update($this->formId, ['member_only' => 1]);

        $stranger = $this->forms->forms(false);
        $member = $this->forms->forms(true);

        self::assertSame([], $stranger, 'a members-only form was listed to a stranger');
        self::assertCount(1, $member);
    }

    public function testAClosedFormKeepsItsResponses(): void
    {
        $this->ask('Name');
        $this->send([]);

        $this->forms->update($this->formId, ['is_open' => 0]);

        self::assertSame([], $this->forms->forms(true), 'a closed form is still taking answers');
        self::assertCount(1, $this->forms->responses($this->formId), 'closing a form lost its responses');
    }

    /**
     * Absent means leave alone. A handler that reads absence as a value is one
     * that will eventually destroy something it was never asked about — which
     * has happened twice in this codebase, once in shipped code.
     */
    public function testSavingOneFieldDoesNotClearTheOthers(): void
    {
        $this->forms->update($this->formId, [
            'description'   => 'Tell us about yourself.',
            'notify_emails' => 'office@example.test',
            'member_only'   => 1,
        ]);

        $this->forms->update($this->formId, ['title' => 'Connect card 2026']);

        $form = (array) $this->forms->find($this->formId);

        self::assertSame('Connect card 2026', $form['title']);
        self::assertSame('Tell us about yourself.', $form['description']);
        self::assertSame('office@example.test', $form['notify_emails']);
        self::assertSame(1, (int) $form['member_only']);
    }

    /**
     * Who hears about a response is named by the form, and anything that is not
     * an address is dropped rather than attempted — a typo would otherwise fail
     * at send time, inside a cron job, where nobody sees it.
     */
    public function testOnlyRealAddressesAreNotified(): void
    {
        $this->forms->update($this->formId, [
            'notify_emails' => "Office@example.test, not-an-address; sam@example.test\noffice@example.test",
        ]);

        self::assertSame(
            ['office@example.test', 'sam@example.test'],
            $this->forms->notifyAddresses((array) $this->forms->find($this->formId))
        );
    }
}
