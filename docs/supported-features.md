# Supported-feature cookbook

> Moved out of the README; nothing was removed.

The examples below mirror every row in the upstream Supported features table.
They assume an initialized, ready `$client`; IDs such as `$chatId`, `$groupId`,
and `$messageId` must come from your own account. The machine-readable mapping
is [`supported-features.json`](../supported-features.json).

Import the classes used by the recipes you copy:

```php
use Pam\WhatsApp\Button;
use Pam\WhatsApp\Buttons;
use Pam\WhatsApp\Chat;
use Pam\WhatsApp\ContactList;
use Pam\WhatsApp\CreateChannelOptions;
use Pam\WhatsApp\GroupChat;
use Pam\WhatsApp\GroupMentionSend;
use Pam\WhatsApp\ListMessage;
use Pam\WhatsApp\Location;
use Pam\WhatsApp\LocationSendOptions;
use Pam\WhatsApp\MessageSendOptions;
use Pam\WhatsApp\Poll;
use Pam\WhatsApp\Event\MessageReceived;
```

<!-- feature:multi-device -->
### Multi Device ✅

Persist the linked-device profile so subsequent starts reconnect without a new QR:

```php
$client = new Client(new ClientOptions(
    authStrategy: new LocalAuth(new LocalAuthOptions('main', __DIR__.'/.sessions')),
));
$client->initialize();
$client->run();
```

<!-- feature:send-messages -->
### Send messages ✅

```php
$client->sendMessageToNumber('+55 (11) 99999-9999', "Hello!\nSecond line.");
$client->sendMessage($chatId, 'Hello using a resolved chat ID.');
```

<!-- feature:receive-messages -->
### Receive messages ✅

```php
$client->onMessage(static function (MessageReceived $event): void {
    printf("%s: %s\n", $event->message->from, $event->message->body);
});
```

<!-- feature:send-media-image-audio-document -->
### Send images, audio, and documents ✅

```php
$client->sendImageToNumber($phone, __DIR__.'/photo.jpg', 'A photo');
$client->sendAudioToNumber($phone, __DIR__.'/voice.ogg', asVoiceNote: true);
$client->sendDocumentToNumber($phone, __DIR__.'/invoice.pdf', 'Invoice');
```

<!-- feature:send-media-video -->
### Send video ✅

Video sending requires Google Chrome, matching the upstream requirement:

```php
$client->sendVideoToNumber($phone, __DIR__.'/demo.mp4', 'Demo video');
$client->sendVideoToNumber($phone, __DIR__.'/animation.mp4', asGif: true);
```

<!-- feature:send-stickers -->
### Send stickers ✅

```php
$client->sendStickerToNumber($phone, __DIR__.'/sticker.webp', 'My pack', 'My app');
```

Video-to-sticker conversion uses the `ffmpegPath` configured in `ClientOptions`.

<!-- feature:receive-media -->
### Receive images, audio, video, and documents ✅

```php
$client->onMessage(static function (MessageReceived $event): void {
    $message = $event->message;
    if (!$message->hasMedia) return;

    $media = $message->downloadMedia();
    if ($media !== null) {
        $binary = base64_decode($media->data, true);
        if ($binary !== false) {
            file_put_contents(__DIR__.'/download.bin', $binary);
        }
    }
});
```

Use `downloadMediaStream()` for large files, as shown later in this README.

<!-- feature:send-contact-cards -->
### Send contact cards ✅

```php
$contact = $client->getContactById('5511999999999@c.us');
$client->sendMessage($chatId, new ContactList([$contact]));
```

<!-- feature:send-location -->
### Send location ✅

```php
$location = new Location(
    -23.5505,
    -46.6333,
    new LocationSendOptions(name: 'São Paulo', address: 'SP, Brazil'),
);
$client->sendMessage($chatId, $location);
```

<!-- feature:send-buttons -->
### Send buttons ❌ deprecated upstream

WhatsApp deprecated this format. The type remains for source compatibility, but
delivery is not guaranteed and new applications should not depend on it:

```php
$legacy = new Buttons('Choose an option', [new Button('Continue', 'continue')]);
$client->sendMessage($chatId, $legacy); // May be rejected by current WhatsApp builds.
```

<!-- feature:send-lists -->
### Send lists ❌ deprecated upstream

```php
$legacy = new ListMessage('Choose a product', 'Open list', [[
    'title' => 'Products',
    'rows' => [['id' => 'coffee', 'title' => 'Coffee']],
]]);
$client->sendMessage($chatId, $legacy); // May be rejected by current WhatsApp builds.
```

<!-- feature:receive-location -->
### Receive location ✅

```php
$client->onMessage(static function (MessageReceived $event): void {
    $location = $event->message->location;
    if ($location !== null) {
        printf("Coordinates: %f, %f\n", $location->latitude, $location->longitude);
    }
});
```

<!-- feature:message-replies -->
### Reply to messages ✅

```php
$client->onMessage(static function (MessageReceived $event): void {
    $event->message->reply('Thanks for your message!');
});
```

<!-- feature:join-groups-by-invite -->
### Join groups by invite ✅

Pass only the invite code, not the complete URL:

```php
$groupId = $client->acceptInvite('AbCdEfGhIjKlMnOpQrStUv');
```

<!-- feature:get-group-invite -->
### Get a group invite ✅

```php
$group = $client->getChatById($groupId);
if ($group instanceof GroupChat) {
    $inviteCode = $group->getInviteCode();
}
```

<!-- feature:modify-group-info -->
### Modify group subject and description ✅

```php
if ($group instanceof GroupChat) {
    $group->setSubject('Customer community');
    $group->setDescription('Support and product announcements');
}
```

<!-- feature:modify-group-settings -->
### Modify group settings ✅

```php
if ($group instanceof GroupChat) {
    $group->setMessagesAdminsOnly(true);
    $group->setInfoAdminsOnly(true);
    $group->setAddMembersAdminsOnly(true);
}
```

<!-- feature:add-group-participants -->
### Add group participants ✅

```php
if ($group instanceof GroupChat) {
    $result = $group->addParticipants(['5511999999999@c.us']);
}
```

<!-- feature:kick-group-participants -->
### Remove group participants ✅

```php
if ($group instanceof GroupChat) {
    $result = $group->removeParticipants(['5511999999999@c.us']);
}
```

<!-- feature:promote-demote-participants -->
### Promote and demote group participants ✅

```php
if ($group instanceof GroupChat) {
    $group->promoteParticipants(['5511999999999@c.us']);
    $group->demoteParticipants(['5511999999999@c.us']);
}
```

<!-- feature:mention-users -->
### Mention users ✅

```php
$client->sendMessage(
    $chatId,
    'Hello @5511999999999',
    new MessageSendOptions(mentions: ['5511999999999@c.us']),
);
```

<!-- feature:mention-groups -->
### Mention groups ✅

```php
$client->sendMessage(
    $chatId,
    'See @Support',
    new MessageSendOptions(groupMentions: [
        new GroupMentionSend('Support', '120363000000000000@g.us'),
    ]),
);
```

<!-- feature:mute-unmute-chats -->
### Mute and unmute chats ✅

```php
$chat = $client->getChatById($chatId);
if ($chat instanceof Chat) {
    $chat->mute(new DateTimeImmutable('+1 hour'));
    $chat->unmute();
}
```

<!-- feature:block-unblock-contacts -->
### Block and unblock contacts ✅

```php
$contact = $client->getContactById('5511999999999@c.us');
$contact->block();
$contact->unblock();
```

<!-- feature:get-contact-info -->
### Get contact information ✅

```php
$contact = $client->getContactById('5511999999999@c.us');
printf("%s (%s)\n", $contact->name, $contact->id->serialized);
```

<!-- feature:get-profile-pictures -->
### Get profile pictures ✅

```php
$url = $client->getProfilePicUrl('5511999999999@c.us');
```

<!-- feature:set-user-status -->
### Set the user status message ✅

```php
$client->setStatus('Available — powered by PAM');
```

<!-- feature:react-to-messages -->
### React to messages ✅

```php
$message = $client->getMessageById($messageId);
$message?->react('👍');
```

<!-- feature:create-polls -->
### Create polls ✅

```php
$poll = new Poll('Where should we have lunch?', ['Pizza', 'Sushi', 'Salad']);
$client->sendMessage($chatId, $poll);
```

<!-- feature:channels -->
### Channels ✅

```php
$channels = $client->getChannels();
$created = $client->createChannel('Product news', new CreateChannelOptions(
    description: 'Release announcements',
));
if (isset($channels[0])) {
    $channels[0]->sendMessage('A new version is available!');
}
```

<!-- feature:vote-in-polls -->
### Vote in polls ✅

```php
$pollMessage = $client->getMessageById($messageId);
$pollMessage?->vote(['Pizza']); // Select one or more options by name.
```

<!-- feature:communities -->
### Communities 🔜 planned upstream

The upstream library does not yet advertise Communities as supported, so this
package intentionally does not claim a complete Communities API. You can inspect
the experimental WhatsApp Web feature flag without treating it as support:

```php
$availableInThisWebBuild = $client->interface?->checkFeatureStatus('communities') ?? false;
```

This flag does not provide community creation or administration guarantees.

