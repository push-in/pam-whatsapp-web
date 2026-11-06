<?php

declare(strict_types=1);

namespace Pam\WhatsApp\Tests\Unit;

use Pam\WhatsApp\Bridge\BridgeEvent;
use Pam\WhatsApp\Client;
use Pam\WhatsApp\ClientState;
use Pam\WhatsApp\ConnectionState;
use Pam\WhatsApp\DisconnectionReason;
use Pam\WhatsApp\Contract\MessageData;
use Pam\WhatsApp\Contract\Session;
use Pam\WhatsApp\Event\MessageReceived;
use Pam\WhatsApp\Event\Authenticated;
use Pam\WhatsApp\Event\ConnectionStateChanged;
use Pam\WhatsApp\Event\BatteryChanged;
use Pam\WhatsApp\Event\CallReceived;
use Pam\WhatsApp\Event\GroupNotification;
use Pam\WhatsApp\Event\LoadingScreen;
use Pam\WhatsApp\Event\RemoteSessionSaved;
use Pam\WhatsApp\Event\MessageAcknowledged;
use Pam\WhatsApp\Event\MessageLifecycle;
use Pam\WhatsApp\Event\QrCodeReceived;
use Pam\WhatsApp\Event\Ready;
use Pam\WhatsApp\EventType;
use Pam\WhatsApp\Message;
use Pam\WhatsApp\MessageContentType;
use Pam\WhatsApp\MessageType;
use Pam\WhatsApp\MessageMedia;
use Pam\WhatsApp\GroupChat;
use Pam\WhatsApp\GroupParticipantAction;
use Pam\WhatsApp\MembershipRequestMethod;
use Pam\WhatsApp\DeviceType;
use Pam\WhatsApp\MessageAck;
use Pam\WhatsApp\ContentKind;
use Pam\WhatsApp\ScheduledEvent;
use Pam\WhatsApp\PaymentStatus;
use Pam\WhatsApp\ContactType;
use Pam\WhatsApp\VerifiedLevel;
use Pam\WhatsApp\BusinessContact;
use Pam\WhatsApp\BusinessHoursMode;
use Pam\WhatsApp\CreateGroupOptions;
use Pam\WhatsApp\CreateGroupResult;
use Pam\WhatsApp\GroupNotificationType;
use Pam\WhatsApp\InterfaceController;
use Pam\WhatsApp\ScheduledEventResponse;
use Pam\WhatsApp\ScheduledEventCallType;
use Pam\WhatsApp\Event\ContactChanged;
use Pam\WhatsApp\Event\Disconnected;
use Pam\WhatsApp\Event\PairingCodeReceived;
use Pam\WhatsApp\PollVote;
use Pam\WhatsApp\PhoneNumber;
use Pam\WhatsApp\RetryOptions;
use Pam\WhatsApp\ClientOptions;
use Pam\WhatsApp\LogLevel;
use Pam\WhatsApp\Exception\ContactNotFoundException;
use Pam\WhatsApp\Reaction;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testLifecycleMessageAndReplyFlow(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $qr = null;
        $readyAt = null;
        $reply = null;
        $client->onQrCode(static function (QrCodeReceived $event) use (&$qr): void {
            $qr = $event->code;
        });
        $client->onReady(static function (Ready $event) use (&$readyAt): void {
            $readyAt = $event->timestamp;
        });
        $client->onMessage(static function (MessageReceived $event) use (&$reply): void {
            $reply = $event->message->reply('pong');
        });

        $client->initialize();
        $client->pump();

        self::assertSame('qr-payload', $qr);
        self::assertSame(101, $readyAt);
        self::assertSame(ClientState::Ready, $client->state);
        self::assertInstanceOf(InterfaceController::class, $client->interface);
        self::assertInstanceOf(Message::class, $reply);
        self::assertSame('pong', $reply->body);
        self::assertSame('incoming-id', $session->quotedMessageId);
    }

    public function testReadyClientExposesTheCompleteInterfaceController(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();
        $interface = $client->interface;
        self::assertNotNull($interface);

        self::assertSame('opened', $interface->openChatWindow('chat@c.us'));
        $interface->openChatDrawer('chat@c.us');
        $interface->openChatSearch('chat@c.us');
        $interface->openChatWindowAt('message-id');
        $interface->openMessageDrawer('message-id');
        $interface->closeRightDrawer();
        self::assertSame(['communities' => true], $interface->getFeatures());
        self::assertTrue($interface->checkFeatureStatus('communities'));
        $interface->enableFeatures(['communities', 'channels']);
        $interface->disableFeatures(['channels']);

        self::assertSame(['chat@c.us'], $session->lastArguments['openChatDrawer']);
        self::assertSame([['communities', 'channels']], $session->lastArguments['enableFeatures']);
        self::assertSame([['channels']], $session->lastArguments['disableFeatures']);
    }

    public function testItSendsTextOnlyWhenReady(): void
    {
        $client = Client::forSession(new ClientFakeSession());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not ready');

        $client->sendMessage('5511999999999@c.us', 'hello');
    }

    public function testItRejectsASecondInitialization(): void
    {
        $client = Client::forSession(new ClientFakeSession());
        $client->initialize();

        $this->expectException(\LogicException::class);
        $client->initialize();
    }

    public function testItHydratesChatsContactsVersionAndConnectionState(): void
    {
        $client = Client::forSession(new ClientFakeSession());
        $client->initialize();

        $chats = $client->getChats();
        $contacts = $client->getContacts();

        self::assertCount(1, $chats);
        self::assertSame('5511999999999@c.us', $chats[0]->id->serialized);
        self::assertSame('c.us', $chats[0]->id->server);
        self::assertSame('Support', $chats[0]->name);
        self::assertCount(1, $contacts);
        self::assertSame('5511999999999@c.us', $contacts[0]->id->serialized);
        self::assertSame('5511999999999', $contacts[0]->id->user);
        self::assertSame('Alice', $contacts[0]->name);
        self::assertInstanceOf(BusinessContact::class, $contacts[0]);
        self::assertSame('Coffee', $contacts[0]->businessProfile->categories[0]->localized_display_name);
        self::assertSame(BusinessHoursMode::SpecificHours, $contacts[0]->businessProfile->businessHours?->config['mon']->mode);
        self::assertSame('2.3000.0', $client->getWWebVersion());
        self::assertSame(ConnectionState::Connected, $client->getState());
        self::assertSame('me@c.us', $client->info?->wid->serialized);
        self::assertSame('PAM', $client->info?->pushname);
        self::assertSame('2.24.1', $client->info?->phone?->wa_version);
        self::assertSame(87, $client->info?->getBatteryStatus()->battery);
    }

    public function testContactHydratesMetadataAndExposesOperations(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();
        $contact = $client->getContacts()[0];

        self::assertTrue($contact->isWAContact);
        self::assertSame(['vip'], $contact->labels);
        self::assertSame(ContactType::Incoming, $contact->type);
        self::assertSame(VerifiedLevel::High, $contact->verifiedLevel);
        self::assertSame('+55 11 99999-9999', $contact->getFormattedNumber());
        self::assertSame('55', $contact->getCountryCode());
        self::assertSame('https://example.com/profile.jpg', $contact->getProfilePicUrl());
        self::assertSame('Available', $contact->getAbout());
        self::assertSame('community@g.us', $contact->getCommonGroups()[0]->serialized);
        self::assertSame('status@broadcast', $contact->getBroadcast()?->id->serialized);
        self::assertTrue($contact->block());
        self::assertTrue($contact->isBlocked);
        self::assertTrue($contact->unblock());
        self::assertFalse($contact->isBlocked);
    }

    public function testItSendsTypedMediaThroughTheContentPipeline(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();

        $client->sendMessage(
            '5511999999999@c.us',
            new MessageMedia('image/png', base64_encode('png')),
        );

        self::assertSame(ContentKind::Media->value, $session->lastContent['kind'] ?? null);
        self::assertSame('image/png', $session->lastContent['media']['mimetype'] ?? null);
    }

    public function testItNormalizesAndSendsToAnUnsavedPhoneNumber(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();

        $message = $client->sendMessageToNumber('+55 (11) 99999-9999', "hello\nworld");

        self::assertSame('5511999999999', (string) new PhoneNumber('+55 (11) 99999-9999'));
        self::assertSame('5511999999999@c.us', $session->lastChatId);
        self::assertSame("hello\nworld", $message->body);
        self::assertSame(['5511999999999'], $session->lastArguments['getNumberId']);
    }

    public function testPhoneNumberRejectsALeadingZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without a leading zero');

        new PhoneNumber('(081) 99999-9999');
    }

    public function testPhoneNumberRejectsInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PhoneNumber('123');
    }

    public function testItRetriesTransientSendFailuresAndLogsContext(): void
    {
        $session = new ClientFakeSession();
        $session->sendFailures = 2;
        $logs = [];
        $client = new Client(new ClientOptions(
            session: $session,
            logger: static function (LogLevel $level, string $message, array $context) use (&$logs): void {
                $logs[] = [$level, $message, $context];
            },
        ));
        $client->initialize();

        $message = $client->sendMessageToNumber(
            '55 11 99999-9999',
            'retried',
            retry: new RetryOptions(maxAttempts: 3, initialDelayMs: 0),
        );

        self::assertSame('retried', $message->body);
        self::assertSame(3, $session->sendAttempts);
        self::assertCount(2, array_filter(
            $logs,
            static fn (array $log): bool => $log[0] === LogLevel::Warning,
        ));
    }

    public function testItRejectsNumbersThatAreNotRegistered(): void
    {
        $session = new ClientFakeSession();
        $session->numberExists = false;
        $client = Client::forSession($session);
        $client->initialize();

        $this->expectException(ContactNotFoundException::class);
        $this->expectExceptionMessage('not registered');

        $client->sendMessageToNumber('+55 11 99999-9999', 'hello');
    }

    public function testItExposesHealthySessionDiagnostics(): void
    {
        $client = Client::forSession(new ClientFakeSession());
        $client->initialize();

        $diagnostics = $client->diagnoseSession();

        self::assertTrue($diagnostics->healthy());
        self::assertSame('2.3000.0', $diagnostics->webVersion);
        self::assertSame('me@c.us', $diagnostics->accountId);
    }

    public function testVideoHelperUsesTheTypedMediaPipeline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pam-video-');
        self::assertIsString($path);
        $videoPath = $path.'.mp4';
        self::assertTrue(rename($path, $videoPath));
        file_put_contents($videoPath, 'video');

        try {
            $session = new ClientFakeSession();
            $client = Client::forSession($session);
            $client->initialize();
            $client->sendVideoToNumber('+55 11 99999-9999', $videoPath, 'Demo', asGif: true);

            self::assertSame('video/mp4', $session->lastContent['media']['mimetype'] ?? null);
            self::assertTrue($session->lastOptions['sendVideoAsGif'] ?? false);
            self::assertSame('Demo', $session->lastOptions['caption'] ?? null);
        } finally {
            @unlink($videoPath);
        }
    }

    public function testItLogsOutAndClosesTheClientState(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();

        $client->logout();

        self::assertTrue($session->loggedOut);
        self::assertSame(ClientState::Closed, $client->state);
    }

    public function testItExposesChatPresenceAndIdentityOperations(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();

        self::assertTrue($client->archiveChat('chat@c.us'));
        self::assertFalse($client->unarchiveChat('chat@c.us'));
        self::assertTrue($client->pinChat('chat@c.us'));
        self::assertFalse($client->unpinChat('chat@c.us'));
        self::assertTrue($client->muteChat('chat@c.us')->isMuted);
        self::assertFalse($client->unmuteChat('chat@c.us')->isMuted);
        self::assertSame('5511999999999@c.us', $client->getNumberId('5511999999999')?->serialized);
        self::assertTrue($client->isRegisteredUser('5511999999999'));
        self::assertSame('+55 11 99999-9999', $client->getFormattedNumber('5511999999999'));
        self::assertSame('55', $client->getCountryCode('+55 11'));
        self::assertTrue($client->setDisplayName('PAM'));
        self::assertTrue($client->syncHistory('chat@c.us'));

        $client->markChatUnread('chat@c.us');
        $client->sendPresenceAvailable();
        $client->sendPresenceUnavailable();
        $client->setStatus('Building with PAM');

        self::assertContains('setStatus', $session->invocations);
        self::assertContains('sendPresenceAvailable', $session->invocations);
    }

    public function testItCreatesGroupsWithTypedOptionsAndParticipantResults(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $result = $client->createGroup(
            'PAM Community',
            ['member@c.us', 'missing@c.us'],
            new CreateGroupOptions(
                messageTimer: 86_400,
                parentGroupId: 'parent@g.us',
                membershipApprovalMode: true,
                isAnnounce: true,
            ),
        );

        self::assertInstanceOf(CreateGroupResult::class, $result);
        self::assertSame('community@g.us', $result->gid->serialized);
        self::assertSame(200, $result->participants['member@c.us']->statusCode);
        self::assertSame(404, $result->participants['missing@c.us']->statusCode);
        self::assertSame(86_400, $session->lastArguments['createGroup'][2]['messageTimer']);
        self::assertTrue($session->lastArguments['createGroup'][2]['isAnnounce']);
    }

    public function testItExposesRemainingIdentityMediaAndUtilityOperations(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();

        self::assertSame('joined@g.us', $client->acceptInvite('invite'));
        self::assertSame('Invited group', $client->getInviteInfo('invite')['subject']);
        self::assertSame('ABCD1234', $client->requestPairingCode('5511999999999'));
        $client->cancelPairingCode();
        $client->resetState();
        self::assertSame('https://call.whatsapp.com/voice', $client->createCallLink(
            new \DateTimeImmutable('@1700000000'),
            ScheduledEventCallType::Voice,
        ));
        self::assertTrue($client->sendResponseToScheduledEvent(ScheduledEventResponse::Going, 'event-message'));
        $client->sendReaction('message-id', '👍');
        self::assertSame('found', $client->searchMessages('query')[0]->body);
        self::assertSame('found', $client->getMessageById('found-message')?->body);
        self::assertSame('Priority', $client->getLabelById('1')->name);
        self::assertSame('Support', $client->getChatsByLabelId('1')[0]->name);
        self::assertTrue($client->setProfilePicture(new MessageMedia('image/png', base64_encode('png'))));
        self::assertTrue($client->deleteProfilePicture());
        $client->revokeStatusMessage('status-message');
        $client->setAutoDownloadAudio(true);
        $client->setAutoDownloadDocuments(true);
        $client->setAutoDownloadPhotos(true);
        $client->setAutoDownloadVideos(true);
        $client->setBackgroundSync(true);
        self::assertSame(2, $client->getContactDeviceCount('member@c.us'));
        $client->saveOrEditAddressbookContact('5511999999999', 'Alice', 'Doe', true);
        $client->deleteAddressbookContact('5511999999999');
        self::assertSame('member@lid', $client->getContactLidAndPhone(['member@c.us'])[0]->lid);
    }

    public function testItHydratesPairingContactReactionAndVoteEvents(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $authenticated = $pairing = $contact = $reaction = $vote = null;
        $client->on(EventType::Authenticated, static function (Authenticated $event) use (&$authenticated): void {
            $authenticated = $event;
        });
        $client->on(EventType::PairingCodeReceived, static function (PairingCodeReceived $event) use (&$pairing): void {
            $pairing = $event;
        });
        $client->on(EventType::ContactChanged, static function (ContactChanged $event) use (&$contact): void {
            $contact = $event;
        });
        $client->on(EventType::MessageReaction, static function (Reaction $event) use (&$reaction): void {
            $reaction = $event;
        });
        $client->on(EventType::VoteUpdated, static function (PollVote $event) use (&$vote): void {
            $vote = $event;
        });
        $client->initialize();

        $session->emit(new BridgeEvent(EventType::PairingCodeReceived, ['code' => 'ABCD1234']));
        $session->emit(new BridgeEvent(EventType::Authenticated, [
            'timestamp' => 124,
            'authPayload' => ['restored' => true],
        ]));
        $session->emit(new BridgeEvent(EventType::ContactChanged, [
            'message' => $session->messagePayload('change-message', 'Number changed'),
            'oldId' => 'old@c.us', 'newId' => 'new@c.us', 'isContact' => true,
        ]));
        $session->emit(new BridgeEvent(EventType::MessageReaction, ['reaction' => [
            'id' => ['fromMe' => false, 'remote' => 'chat@c.us', 'id' => 'reaction-id', '_serialized' => 'reaction-id'],
            'orphan' => 0, 'orphanReason' => null, 'timestamp' => 123,
            'reaction' => '👍', 'read' => true,
            'msgId' => ['fromMe' => false, 'remote' => 'chat@c.us', 'id' => 'parent-id', '_serialized' => 'parent-id'],
            'senderId' => 'member@c.us',
            'ack' => MessageAck::Read->value,
        ]]));
        $session->emit(new BridgeEvent(EventType::VoteUpdated, ['vote' => [
            'voter' => 'member@c.us', 'selectedOptions' => [['id' => 1, 'name' => 'Pizza']],
            'interractedAtTs' => 123_000,
            'parentMessage' => array_replace($session->messagePayload('poll-message', 'Lunch?'), [
                'type' => MessageType::PollCreation->value,
                'contentType' => MessageContentType::Poll->value,
            ]),
        ]]));

        self::assertSame('ABCD1234', $pairing?->code);
        self::assertSame(124, $authenticated?->timestamp);
        self::assertSame(['restored' => true], $authenticated?->authPayload);
        self::assertSame('new@c.us', $contact?->newId);
        self::assertSame('👍', $reaction?->reaction);
        self::assertSame('Pizza', $vote?->selectedOptions[0]->name);
    }

    public function testItDispatchesTypedAcknowledgementLifecycleAndDomainEvents(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $acknowledged = null;
        $created = null;
        $state = null;
        $read = null;
        $client->on(EventType::MessageAcknowledged, static function (MessageAcknowledged $event) use (&$acknowledged): void {
            $acknowledged = $event;
        });
        $client->on(EventType::MessageCreated, static function (MessageLifecycle $event) use (&$created): void {
            $created = $event;
        });
        $client->on(EventType::StateChanged, static function (ConnectionStateChanged $event) use (&$state): void {
            $state = $event;
        });
        $client->onMessageRead(static function (MessageAcknowledged $event) use (&$read): void {
            $read = $event;
        });
        $client->initialize();

        $message = [
            'id' => 'event-message',
            'chatId' => 'chat@c.us',
            'from' => 'me@c.us',
            'to' => 'chat@c.us',
            'body' => 'hello',
            'fromMe' => true,
            'timestamp' => 104,
            'type' => MessageType::Text->value,
            'contentType' => MessageContentType::Text->value,
        ];
        $session->emit(new BridgeEvent(EventType::MessageAcknowledged, ['message' => $message, 'ack' => 5]));
        $session->emit(new BridgeEvent(EventType::MessageCreated, ['message' => $message]));
        $session->emit(new BridgeEvent(EventType::StateChanged, ['state' => ConnectionState::Connected->value]));

        self::assertSame('event-message', $acknowledged?->message->id->serialized);
        self::assertSame(5, $acknowledged?->ack->value);
        self::assertSame('event-message', $read?->message->id->serialized);
        self::assertSame(EventType::MessageCreated, $created?->type);
        self::assertSame('hello', $created?->message->body);
        self::assertSame(ConnectionState::Connected, $state?->state);
    }

    public function testItHydratesGroupNotificationEvents(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $notification = null;
        $client->on(EventType::GroupJoined, static function (GroupNotification $event) use (&$notification): void {
            $notification = $event;
        });
        $client->initialize();

        $session->emit(new BridgeEvent(EventType::GroupJoined, [
            'id' => 'notification-id',
            'author' => 'admin@c.us',
            'body' => 'Alice joined',
            'chatId' => 'community@g.us',
            'recipientIds' => ['alice@c.us'],
            'timestamp' => 105,
            'type' => GroupNotificationType::Add->value,
        ]));

        self::assertSame(EventType::GroupJoined, $notification?->eventType);
        self::assertSame(GroupNotificationType::Add, $notification?->type);
        self::assertSame('community@g.us', $notification?->chatId);
        self::assertSame(['alice@c.us'], $notification?->recipientIds);
        self::assertSame('community@g.us', $notification?->getChat()->id->serialized);
        self::assertSame('Alice', $notification?->getContact()->name);
        self::assertSame('Alice', $notification?->getRecipients()[0]->name);
        self::assertSame('Welcome', $notification?->reply('Welcome')->body);
    }

    public function testItHydratesATypedDisconnectionReasonAndClosesState(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $disconnected = null;
        $client->on(EventType::Disconnected, static function (Disconnected $event) use (&$disconnected): void {
            $disconnected = $event;
        });
        $client->initialize();

        $session->emit(new BridgeEvent(EventType::Disconnected, [
            'reason' => DisconnectionReason::Conflict->value,
        ]));

        self::assertSame(ClientState::Closed, $client->state);
        self::assertSame(DisconnectionReason::Conflict, $disconnected?->reason);
    }

    public function testItHydratesOperationalEvents(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $battery = null;
        $call = null;
        $loading = null;
        $saved = null;
        $client->on(EventType::BatteryChanged, static function (BatteryChanged $event) use (&$battery): void {
            $battery = $event;
        });
        $client->on(EventType::CallReceived, static function (CallReceived $event) use (&$call): void {
            $call = $event;
        });
        $client->on(EventType::LoadingScreen, static function (LoadingScreen $event) use (&$loading): void {
            $loading = $event;
        });
        $client->on(EventType::RemoteSessionSaved, static function (RemoteSessionSaved $event) use (&$saved): void {
            $saved = $event;
        });
        $client->initialize();

        $session->emit(new BridgeEvent(EventType::BatteryChanged, ['battery' => 87, 'plugged' => true]));
        $session->emit(new BridgeEvent(EventType::CallReceived, [
            'id' => 'call-id',
            'peerId' => 'caller@c.us',
            'timestamp' => 1_700_000_003,
            'isVideo' => true,
            'isGroup' => false,
            'canHandleLocally' => true,
            'outgoing' => false,
            'webClientShouldHandle' => true,
            'participantIds' => ['caller@c.us'],
        ]));
        $session->emit(new BridgeEvent(EventType::LoadingScreen, ['percent' => 42, 'message' => 'WhatsApp']));
        $session->emit(new BridgeEvent(EventType::RemoteSessionSaved, ['timestamp' => 106]));

        self::assertSame(87, $battery?->battery);
        self::assertSame('caller@c.us', $call?->peerId);
        self::assertTrue($call?->isVideo ?? false);
        self::assertSame(1_700_000_003, $call?->call->timestamp);
        $call?->call->reject();
        self::assertSame(['caller@c.us', 'call-id'], $session->lastArguments['rejectCall']);
        self::assertSame(42, $loading?->percent);
        self::assertSame(106, $saved?->timestamp);
    }

    public function testChatExposesUpstreamConversationOperations(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();
        $chat = $client->getChats()[0];

        self::assertTrue($chat->clearMessages());
        self::assertTrue($chat->delete());
        $chat->archive();
        $chat->unarchive();
        self::assertTrue($chat->pin());
        self::assertFalse($chat->unpin());
        self::assertTrue($chat->mute()->isMuted);
        self::assertTrue($chat->isMuted);
        self::assertFalse($chat->unmute()->isMuted);
        self::assertFalse($chat->isMuted);
        $chat->sendStateTyping();
        $chat->sendStateRecording();
        self::assertTrue($chat->clearState());
        self::assertSame('Alice', $chat->getContact()->name);
        self::assertSame('history-message', $chat->fetchMessages(new \Pam\WhatsApp\MessageSearchOptions(1))[0]->id->serialized);
        self::assertTrue($chat->syncHistory());
        self::assertSame('Priority', $chat->getLabels()[0]->name);
        self::assertSame('pinned-message', $chat->getPinnedMessages()[0]->id->serialized);
        $chat->changeLabels([1]);
        $chat->addOrEditCustomerNote('VIP');
        self::assertSame('VIP', $chat->getCustomerNote()?->content);
        $chat->markUnread();
        self::assertContains('markChatUnread', $session->invocations);
        self::assertContains('addOrRemoveLabels', $session->invocations);
        self::assertContains('addOrEditCustomerNote', $session->invocations);
    }

    public function testClientAndLabelExposeBusinessLabelOperations(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();

        $label = $client->getLabels()[0];

        self::assertSame('1', $label->id);
        self::assertSame('#ff0000', $label->hexColor);
        self::assertSame('Support', $label->getChats()[0]->name);
        self::assertSame('pinned-message', $client->getPinnedMessages('chat@c.us')[0]->id->serialized);
        self::assertSame('VIP', $client->getCustomerNote('chat@c.us')?->content);
    }

    public function testItHydratesAndOperatesAGroupChat(): void
    {
        $session = new ClientFakeSession();
        $client = Client::forSession($session);
        $client->initialize();

        $chat = $client->getChatById('community@g.us');

        self::assertInstanceOf(GroupChat::class, $chat);
        self::assertSame('owner@c.us', $chat->owner?->serialized);
        self::assertSame('Community description', $chat->description);
        self::assertCount(1, $chat->participants);
        self::assertTrue($chat->participants[0]->isAdmin);
        self::assertSame(200, $chat->removeParticipants(['member@c.us'])->status);
        self::assertSame(200, $chat->promoteParticipants(['member@c.us'])->status);
        self::assertSame(200, $chat->demoteParticipants(['member@c.us'])->status);
        self::assertTrue($chat->setSubject('New community'));
        self::assertSame('New community', $chat->name);
        self::assertTrue($chat->setDescription('New description'));
        self::assertSame('New description', $chat->description);
        self::assertTrue($chat->setAddMembersAdminsOnly());
        self::assertTrue($chat->setMessagesAdminsOnly());
        self::assertTrue($chat->setInfoAdminsOnly());
        self::assertTrue($chat->deletePicture());
        self::assertTrue($chat->setPicture(new MessageMedia('image/png', base64_encode('png'))));
        self::assertSame('invite-code', $chat->getInviteCode());
        $chat->revokeInvite();
        $added = $chat->addParticipants(['new@c.us']);
        self::assertIsArray($added);
        self::assertSame(200, $added['new@c.us']->code);
        self::assertTrue($added['new@c.us']->isInviteV4Sent);
        $requests = $chat->getGroupMembershipRequests();
        self::assertSame('requester@c.us', $requests[0]->id->serialized);
        self::assertSame(MembershipRequestMethod::InviteLink, $requests[0]->requestMethod);
        self::assertSame('requester@c.us', $chat->approveGroupMembershipRequests()[0]->requesterId);
        self::assertSame('requester@c.us', $chat->rejectGroupMembershipRequests()[0]->requesterId);
        $chat->leave();

        self::assertContains('leaveGroup', $session->invocations);
        self::assertSame(GroupParticipantAction::Demote->value, $session->lastArguments['modifyGroupParticipants'][1] ?? null);
    }

    public function testMessageHydratesMetadataAndExposesCoreOperations(): void
    {
        $session = new ClientFakeSession();
        $message = new Message($session, new MessageData(
            'rich-message', 'community@g.us', 'community@g.us', 'me@c.us', 'caption', true, 120,
            MessageType::Image,
            MessageContentType::Media,
            [
                'ack' => MessageAck::Read->value,
                'hasMedia' => true,
                'mediaKey' => 'media-key',
                'author' => 'member@c.us',
                'deviceType' => DeviceType::Android->value,
                'isForwarded' => true,
                'forwardingScore' => 2,
                'hasQuotedMsg' => true,
                'mentionedIds' => ['member@c.us'],
                'groupMentions' => [['groupSubject' => 'Community', 'groupJid' => 'community@g.us']],
                'eventLocation' => [
                    'degreesLatitude' => -23.5505,
                    'degreesLongitude' => -46.6333,
                    'name' => 'São Paulo',
                ],
                'links' => [['link' => 'https://example.com', 'isSuspicious' => false]],
                'inviteV4' => [
                    'inviteCode' => 'invite-code',
                    'inviteCodeExp' => 2_000_000_000,
                    'groupId' => 'invited-community@g.us',
                    'groupName' => 'Invited community',
                    'fromId' => 'admin@c.us',
                    'toId' => 'me@c.us',
                ],
            ],
        ));

        self::assertSame(MessageAck::Read, $message->ack);
        self::assertSame(MessageType::Image, $message->type);
        self::assertSame('São Paulo', $message->eventLocation?->name);
        self::assertSame(DeviceType::Android, $message->deviceType);
        self::assertTrue($message->isForwarded);
        self::assertSame('member@c.us', $message->author);
        self::assertSame('quoted-message', $message->getQuotedMessage()?->id->serialized);
        self::assertSame('image/png', $message->downloadMedia()?->mimetype);
        self::assertSame('Alice', $message->getContact()->name);
        self::assertSame('Alice', $message->getMentions()[0]->name);
        self::assertSame('Community', $message->getGroupMentions()[0]->name);
        self::assertSame('edited-message', $message->edit('updated')?->id->serialized);
        self::assertTrue($message->pin(86_400));
        self::assertTrue($message->unpin());
        self::assertSame(1, $message->getInfo()?->readRemaining);
        self::assertSame(200, $message->acceptGroupV4Invite()->status);
        self::assertSame('reloaded', $message->reload()?->body);
        self::assertSame('scheduled-event', $message->editScheduledEvent(new ScheduledEvent(
            'Community meeting',
            new \DateTimeImmutable('@2000000000'),
        ))?->id->serialized);

        $message->forward('destination@c.us');
        $message->react('👍');
        $message->star();
        self::assertTrue($message->isStarred);
        $message->unstar();
        self::assertFalse($message->isStarred);
        $message->delete(true);

        self::assertContains('forwardMessage', $session->invocations);
        self::assertContains('deleteMessage', $session->invocations);
    }

    public function testPollMessageCanVote(): void
    {
        $session = new ClientFakeSession();
        $message = new Message($session, new MessageData(
            'poll-message', 'chat@c.us', 'chat@c.us', 'me@c.us', 'Lunch?', false, 121,
            MessageType::PollCreation,
            MessageContentType::Poll,
        ));

        $message->vote(['Pizza']);

        self::assertSame(['Pizza'], $session->lastArguments['voteMessage'][1] ?? null);
    }

    public function testMessageExposesOrdersPaymentsReactionsAndPollVotes(): void
    {
        $session = new ClientFakeSession();
        $orderMessage = new Message($session, new MessageData(
            'order-message', 'chat@c.us', 'chat@c.us', 'me@c.us', '', false, 121,
            MessageType::Order,
            MessageContentType::Order,
            ['orderId' => 'order-1', 'token' => 'order-token'],
        ));
        $paymentMessage = new Message($session, new MessageData(
            'payment-message', 'chat@c.us', 'chat@c.us', 'me@c.us', '', false, 121,
            MessageType::Payment,
            MessageContentType::Payment,
        ));
        $reactionMessage = new Message($session, new MessageData(
            'reaction-message', 'chat@c.us', 'chat@c.us', 'me@c.us', 'hello', false, 121,
            MessageType::Text,
            MessageContentType::Text,
            ['hasReaction' => true],
        ));
        $pollMessage = new Message($session, new MessageData(
            'poll-message', 'chat@c.us', 'chat@c.us', 'me@c.us', 'Lunch?', false, 121,
            MessageType::PollCreation,
            MessageContentType::Poll,
        ));

        $order = $orderMessage->getOrder();
        self::assertSame('BRL', $order?->currency);
        self::assertSame('Coffee', $order?->products[0]->name);
        self::assertSame('Coffee description', $order?->products[0]->getData()?->description);
        self::assertSame(PaymentStatus::Complete, $paymentMessage->getPayment()?->paymentStatus);
        self::assertSame('👍', $reactionMessage->getReactions()[0]->aggregateEmoji);
        self::assertSame('member@c.us', $reactionMessage->getReactions()[0]->senders[0]->senderId);
        self::assertSame('reaction-message', $reactionMessage->getReactions()[0]->senders[0]->msgId->serialized);
        self::assertSame('Pizza', $pollMessage->getPollVotes()[0]->selectedOptions[0]->name);
        self::assertSame('poll-message', $pollMessage->getPollVotes()[0]->parentMessage->id->serialized);
    }
}

final class ClientFakeSession implements Session
{
    /** @var null|callable(BridgeEvent): void */
    private $listener = null;

    public ?string $quotedMessageId = null;

    public ?string $lastChatId = null;

    /** @var array<string, mixed> */
    public array $lastContent = [];

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public int $sendFailures = 0;

    public int $sendAttempts = 0;

    public bool $numberExists = true;

    public bool $loggedOut = false;

    /** @var list<string> */
    public array $invocations = [];

    /** @var array<string, list<mixed>> */
    public array $lastArguments = [];

    public function initialize(callable $listener): void
    {
        $this->listener = $listener;
        $listener(new BridgeEvent(EventType::QrCode, ['code' => 'qr-payload']));
        $listener(new BridgeEvent(EventType::Authenticated, ['timestamp' => 100]));
        $listener(new BridgeEvent(EventType::Ready, [
            'timestamp' => 101,
            'info' => [
                'wid' => ['server' => 'c.us', 'user' => 'me', '_serialized' => 'me@c.us'],
                'pushname' => 'PAM',
                'platform' => 'android',
                'phone' => [
                    'wa_version' => '2.24.1',
                    'os_version' => '14',
                    'device_manufacturer' => 'Google',
                    'device_model' => 'Pixel',
                    'os_build_number' => 'UP1A',
                ],
            ],
        ]));
    }

    public function emit(BridgeEvent $event): void
    {
        $listener = $this->listener;
        if ($listener === null) {
            throw new \LogicException('Fake session is not initialized.');
        }
        $listener($event);
    }

    public function sendText(string $chatId, string $body, ?string $quotedMessageId = null): MessageData
    {
        $this->sendAttempts++;
        if ($this->sendFailures > 0) {
            $this->sendFailures--;
            throw new \RuntimeException('Temporary bridge failure.');
        }
        $this->lastChatId = $chatId;
        $this->quotedMessageId = $quotedMessageId;

        return new MessageData(
            'sent-id',
            $chatId,
            'me@c.us',
            $chatId,
            $body,
            true,
            102,
            MessageType::Text,
            MessageContentType::Text,
        );
    }

    public function sendContent(string $chatId, array $content, array $options = []): MessageData
    {
        $this->lastContent = $content;
        $this->lastOptions = $options;
        $quotedMessageId = is_string($options['quotedMessageId'] ?? null)
            ? $options['quotedMessageId']
            : null;
        $body = is_string($content['text'] ?? null) ? $content['text'] : '';

        return $this->sendText($chatId, $body, $quotedMessageId);
    }

    public function pump(float $timeoutSeconds): bool
    {
        $listener = $this->listener;
        if ($listener === null) {
            return false;
        }
        $this->listener = null;
        $listener(new BridgeEvent(EventType::MessageReceived, [
            'id' => 'incoming-id',
            'chatId' => '5511999999999@c.us',
            'from' => '5511999999999@c.us',
            'to' => 'me@c.us',
            'body' => '!ping',
            'fromMe' => false,
            'timestamp' => 103,
            'type' => MessageType::Text->value,
            'contentType' => MessageContentType::Text->value,
        ]));

        return true;
    }

    public function invoke(string $method, array $arguments = []): mixed
    {
        $this->invocations[] = $method;
        $this->lastArguments[$method] = $arguments;

        return match ($method) {
            'getWWebVersion' => '2.3000.0',
            'getState' => 1,
            'getBatteryStatus' => ['battery' => 87, 'plugged' => true],
            'openChatWindow' => 'opened',
            'getFeatures' => ['communities' => true],
            'checkFeatureStatus' => true,
            'sendResponseToScheduledEvent' => true,
            'createGroup' => [
                'title' => 'PAM Community',
                'gid' => ['server' => 'g.us', 'user' => 'community', '_serialized' => 'community@g.us'],
                'participants' => [
                    'member@c.us' => [
                        'statusCode' => 200,
                        'message' => 'The participant was added successfully',
                        'isGroupCreator' => false,
                        'isInviteV4Sent' => false,
                    ],
                    'missing@c.us' => [
                        'statusCode' => 404,
                        'message' => 'The phone number is not registered on WhatsApp',
                        'isGroupCreator' => false,
                        'isInviteV4Sent' => false,
                    ],
                ],
            ],
            'acceptInvite' => 'joined@g.us',
            'getInviteInfo' => ['subject' => 'Invited group'],
            'requestPairingCode' => 'ABCD1234',
            'createCallLink' => 'https://call.whatsapp.com/voice',
            'searchMessages' => [$this->messagePayload('found-message', 'found')],
            'getMessageById' => $this->messagePayload('found-message', 'found'),
            'getLabelById' => ['id' => '1', 'name' => 'Priority', 'hexColor' => '#ff0000'],
            'setProfilePicture', 'deleteProfilePicture' => true,
            'getContactDeviceCount' => 2,
            'getContactLidAndPhone' => [['lid' => 'member@lid', 'pn' => 'member@c.us']],
            'getChats' => [[
                'id' => ['_serialized' => '5511999999999@c.us'],
                'formattedTitle' => 'Support',
                'isGroup' => false,
            ]],
            'getContacts' => [[
                'id' => ['server' => 'c.us', 'user' => '5511999999999', '_serialized' => '5511999999999@c.us'],
                'name' => 'Alice',
                'isUser' => true,
                'isBusiness' => true,
                'isWAContact' => true,
                'labels' => ['vip'],
                'type' => ContactType::Incoming->value,
                'verifiedLevel' => VerifiedLevel::High->value,
                'businessProfile' => [
                    'id' => ['server' => 'c.us', 'user' => '5511999999999', '_serialized' => '5511999999999@c.us'],
                    'tag' => 'coffee',
                    'description' => 'Coffee shop',
                    'categories' => [['id' => 'cafe', 'localized_display_name' => 'Coffee']],
                    'profileOptions' => ['cartEnabled' => true],
                    'email' => 'coffee@example.com',
                    'website' => ['https://example.com'],
                    'latitude' => -23.5,
                    'longitude' => -46.6,
                    'businessHours' => [
                        'config' => ['mon' => ['mode' => BusinessHoursMode::SpecificHours->value, 'hours' => [540, 1080]]],
                        'timezone' => 'America/Sao_Paulo',
                    ],
                    'address' => 'São Paulo',
                    'fbPage' => [],
                    'ifProfileLinked' => true,
                    'coverPhoto' => null,
                ],
            ]],
            'getContactById' => [
                'id' => ['_serialized' => '5511999999999@c.us'],
                'name' => 'Alice',
                'isUser' => true,
            ],
            'getChatById' => [
                'id' => ['_serialized' => 'community@g.us'],
                'formattedTitle' => 'Community',
                'isGroup' => true,
                'groupMetadata' => [
                    'owner' => ['server' => 'c.us', 'user' => 'owner', '_serialized' => 'owner@c.us'],
                    'creation' => 1_700_000_000,
                    'desc' => 'Community description',
                    'participants' => [[
                        'id' => ['server' => 'c.us', 'user' => 'member', '_serialized' => 'member@c.us'],
                        'isAdmin' => true,
                        'isSuperAdmin' => false,
                    ]],
                ],
            ],
            'fetchMessages' => [[
                'id' => 'history-message',
                'chatId' => '5511999999999@c.us',
                'from' => '5511999999999@c.us',
                'to' => 'me@c.us',
                'body' => 'history',
                'fromMe' => false,
                'timestamp' => 90,
                'type' => MessageType::Text->value,
                'contentType' => MessageContentType::Text->value,
            ]],
            'getLabels', 'getChatLabels' => [[
                'id' => '1',
                'name' => 'Priority',
                'hexColor' => '#ff0000',
            ]],
            'getChatsByLabelId' => [[
                'id' => ['_serialized' => '5511999999999@c.us'],
                'formattedTitle' => 'Support',
                'isGroup' => false,
            ]],
            'getPinnedMessages' => [[
                'id' => 'pinned-message',
                'chatId' => '5511999999999@c.us',
                'from' => 'me@c.us',
                'to' => '5511999999999@c.us',
                'body' => 'important',
                'fromMe' => true,
                'timestamp' => 91,
                'type' => MessageType::Text->value,
                'contentType' => MessageContentType::Text->value,
            ]],
            'getCustomerNote' => [
                'chatId' => '5511999999999@c.us',
                'content' => 'VIP',
                'createdAt' => 100,
                'id' => 'note-id',
                'modifiedAt' => 101,
                'type' => 1,
            ],
            'archiveChat', 'pinChat', 'setDisplayName', 'syncHistory',
            'clearMessages', 'deleteChat', 'sendChatstate', 'rejectCall', 'setGroupSubject',
            'setGroupDescription', 'setGroupSetting', 'deleteGroupPicture', 'setGroupPicture' => true,
            'unarchiveChat', 'unpinChat' => false,
            'modifyGroupParticipants' => ['status' => 200],
            'getGroupInviteCode' => 'invite-code',
            'revokeGroupInvite' => 'new-invite-code',
            'addGroupParticipants' => [
                'new@c.us' => ['code' => 200, 'message' => 'Added', 'isInviteV4Sent' => true],
            ],
            'getGroupMembershipRequests' => [[
                'id' => ['server' => 'c.us', 'user' => 'requester', '_serialized' => 'requester@c.us'],
                'addedBy' => ['server' => 'c.us', 'user' => 'admin', '_serialized' => 'admin@c.us'],
                'parentGroupId' => null,
                'requestMethod' => 2,
                'timestamp' => 110,
            ]],
            'membershipRequestAction' => [[
                'requesterId' => 'requester@c.us',
                'error' => null,
                'message' => 'Success',
            ]],
            'getQuotedMessage' => $this->messagePayload('quoted-message', 'quoted'),
            'downloadMessageMedia' => [
                'mimetype' => 'image/png',
                'data' => base64_encode('png'),
                'filename' => 'image.png',
                'filesize' => 3,
            ],
            'editMessage' => $this->messagePayload('edited-message', 'updated'),
            'pinMessage' => true,
            'getMessageInfo' => [
                'delivery' => [],
                'deliveryRemaining' => 0,
                'played' => [],
                'playedRemaining' => 0,
                'read' => [[
                    'id' => ['server' => 'c.us', 'user' => 'member', '_serialized' => 'member@c.us'],
                    'timestamp' => 122,
                ]],
                'readRemaining' => 1,
            ],
            'reloadMessage' => $this->messagePayload('rich-message', 'reloaded'),
            'acceptGroupV4Invite' => ['status' => 200],
            'editScheduledEvent' => $this->messagePayload('scheduled-event', 'Community meeting'),
            'getMessageOrder' => [
                'products' => [[
                    'id' => 'product-1', 'price' => '10.00', 'thumbnailUrl' => 'https://example.com/coffee.jpg',
                    'currency' => 'BRL', 'name' => 'Coffee', 'quantity' => 2,
                ]],
                'subtotal' => '20.00', 'total' => '20.00', 'currency' => 'BRL', 'createdAt' => 123,
            ],
            'getProductMetadata' => [
                'id' => 'product-1',
                'name' => 'Coffee',
                'description' => 'Coffee description',
                'retailer_id' => 'coffee-sku',
            ],
            'getMessagePayment' => [
                'id' => 'payment-message', 'paymentCurrency' => 'BRL', 'paymentAmount1000' => 10_000,
                'paymentMessageReceiverJid' => 'merchant@c.us', 'paymentTransactionTimestamp' => 123,
                'paymentStatus' => PaymentStatus::Complete->value,
                'paymentTxnStatus' => PaymentStatus::Complete->value,
                'paymentNote' => 'Thanks',
            ],
            'getMessageReactions' => [[
                'id' => '👍', 'aggregateEmoji' => '👍', 'hasReactionByMe' => false,
                'senders' => [[
                    'id' => ['fromMe' => false, 'remote' => 'chat@c.us', 'id' => 'reaction-1', '_serialized' => 'reaction-1'],
                    'orphan' => 0, 'orphanReason' => null, 'timestamp' => 123,
                    'reaction' => '👍', 'read' => true,
                    'msgId' => [
                        'fromMe' => false,
                        'remote' => 'chat@c.us',
                        'id' => 'reaction-message',
                        '_serialized' => 'reaction-message',
                    ],
                    'senderId' => 'member@c.us', 'ack' => MessageAck::Read->value,
                ]],
            ]],
            'getPollVotes' => [[
                'voter' => 'member@c.us',
                'selectedOptions' => [['id' => 1, 'name' => 'Pizza']],
                'interractedAtTs' => 123_000,
                'parentMessage' => array_replace($this->messagePayload('poll-message', 'Lunch?'), [
                    'type' => MessageType::PollCreation->value,
                    'contentType' => MessageContentType::Poll->value,
                ]),
            ]],
            'muteChat' => ['isMuted' => true, 'muteExpiration' => 2_000_000_000],
            'unmuteChat' => ['isMuted' => false, 'muteExpiration' => 0],
            'getNumberId' => $this->numberExists ? [
                'server' => 'c.us',
                'user' => '5511999999999',
                '_serialized' => '5511999999999@c.us',
            ] : null,
            'getFormattedNumber' => '+55 11 99999-9999',
            'getCountryCode' => '55',
            'getProfilePicUrl' => 'https://example.com/profile.jpg',
            'getContactAbout' => 'Available',
            'getCommonGroups' => [[
                'server' => 'g.us', 'user' => 'community', '_serialized' => 'community@g.us',
            ]],
            'getBroadcastById' => [
                'id' => ['server' => 'broadcast', 'user' => 'status', '_serialized' => 'status@broadcast'],
                'timestamp' => 123, 'totalCount' => 1, 'unreadCount' => 1,
                'msgs' => [$this->messagePayload('status-message', 'Status')],
            ],
            'blockContact' => true,
            default => null,
        };
    }

    public function close(): void
    {
    }

    public function logout(): void
    {
        $this->loggedOut = true;
    }

    /** @return array<string, mixed> */
    public function messagePayload(string $id, string $body): array
    {
        return [
            'id' => $id,
            'chatId' => 'community@g.us',
            'from' => 'me@c.us',
            'to' => 'community@g.us',
            'body' => $body,
            'fromMe' => true,
            'timestamp' => 123,
            'type' => MessageType::Text->value,
            'contentType' => MessageContentType::Text->value,
        ];
    }
}
