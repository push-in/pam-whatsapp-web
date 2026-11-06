<?php

declare(strict_types=1);

namespace Pam\WhatsApp\Bridge;

final class BridgeScript
{
    public static function source(): string
    {
        return UpstreamUtils::functionSource()."\n".<<<'JAVASCRIPT'
(() => {
    'use strict';

    const EVENT = Object.freeze({
        QR_CODE: 1,
        AUTHENTICATED: 2,
        READY: 3,
        MESSAGE_RECEIVED: 4,
        DISCONNECTED: 5,
        ERROR: 6,
        AUTHENTICATION_FAILURE: 7,
        CALL_RECEIVED: 8,
        BATTERY_CHANGED: 9,
        STATE_CHANGED: 10,
        CHAT_ARCHIVED: 11,
        CHAT_REMOVED: 12,
        PAIRING_CODE_RECEIVED: 13,
        CONTACT_CHANGED: 14,
        GROUP_ADMIN_CHANGED: 15,
        GROUP_JOINED: 16,
        GROUP_LEFT: 17,
        GROUP_MEMBERSHIP_REQUEST: 18,
        GROUP_UPDATED: 19,
        LOADING_SCREEN: 20,
        MEDIA_UPLOADED: 21,
        MESSAGE_ACKNOWLEDGED: 22,
        MESSAGE_CIPHERTEXT: 23,
        MESSAGE_CIPHERTEXT_FAILED: 24,
        MESSAGE_CREATED: 25,
        MESSAGE_EDITED: 26,
        MESSAGE_REACTION: 27,
        MESSAGE_REVOKED_EVERYONE: 28,
        MESSAGE_REVOKED_ME: 29,
        REMOTE_SESSION_SAVED: 30,
        UNREAD_COUNT_CHANGED: 31,
        VOTE_UPDATED: 32,
    });
    const CONTENT = Object.freeze({
        TEXT: 1,
        MEDIA: 2,
        LOCATION: 3,
        CONTACT: 4,
        POLL: 5,
        SYSTEM: 6,
        UNKNOWN: 7,
    });
    const DISCONNECT_REASON = Object.freeze({
        LOGOUT: 1,
        CONFLICT: 2,
        UNLAUNCHED: 3,
        QR_RETRY_LIMIT: 4,
    });
    const clientOptions = globalThis.__pamWhatsAppClientOptions ?? Object.freeze({});

    if (globalThis.__pamWhatsAppBridgeState === 1 || globalThis.__pamWhatsAppBridgeState === 2) {
        return;
    }
    globalThis.__pamWhatsAppBridgeState = 1;

    const emit = (type, payload = {}) => {
        globalThis.pamWhatsAppBridge(JSON.stringify({ type, payload }));
    };
    const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
    const serialized = (value) => {
        if (typeof value === 'string') return value;
        if (typeof value?._serialized === 'string') return value._serialized;
        if (typeof value?.user === 'string' && typeof value?.server === 'string') {
            return value.user + '@' + value.server;
        }
        if (typeof value?.fromMe === 'boolean' && value?.remote && typeof value?.id === 'string') {
            const participant = serialized(value.participant);
            return String(value.fromMe) + '_' + serialized(value.remote) + '_' + value.id
                + (participant ? '_' + participant : '');
        }
        const text = value?.toString?.();
        return typeof text === 'string' && text !== '[object Object]' ? text : '';
    };
    const messageIdData = (value) => {
        const complete = serialized(value);
        if (!complete) return null;
        const separator = complete.lastIndexOf('_');
        return {
            fromMe: Boolean(value?.fromMe),
            remote: serialized(value?.remote),
            id: typeof value?.id === 'string'
                ? value.id
                : separator === -1 ? complete : complete.slice(separator + 1),
            _serialized: complete,
        };
    };
    const stickerMetadata = (base64, name, author, categories) => {
        if (!name && !author) return base64;
        const source = Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
        const ascii = (offset, length) => String.fromCharCode(...source.slice(offset, offset + length));
        if (ascii(0, 4) !== 'RIFF' || ascii(8, 4) !== 'WEBP') {
            throw new Error('Sticker output is not a WebP RIFF container.');
        }
        const read32 = (bytes, offset) => bytes[offset] | (bytes[offset + 1] << 8)
            | (bytes[offset + 2] << 16) | (bytes[offset + 3] << 24);
        const read24 = (bytes, offset) => bytes[offset] | (bytes[offset + 1] << 8)
            | (bytes[offset + 2] << 16);
        const write24 = (value) => [value & 255, (value >> 8) & 255, (value >> 16) & 255];
        const write32 = (value) => [value & 255, (value >> 8) & 255, (value >> 16) & 255, (value >> 24) & 255];
        const chunks = [];
        let offset = 12;
        while (offset + 8 <= source.length) {
            const type = ascii(offset, 4);
            const size = read32(source, offset + 4) >>> 0;
            if (offset + 8 + size > source.length) throw new Error('Sticker WebP contains an invalid chunk.');
            chunks.push({ type, data: source.slice(offset + 8, offset + 8 + size) });
            offset += 8 + size + (size % 2);
        }
        let width = 0;
        let height = 0;
        for (const chunk of chunks) {
            if (chunk.type === 'VP8X' && chunk.data.length >= 10) {
                width = read24(chunk.data, 4) + 1;
                height = read24(chunk.data, 7) + 1;
                break;
            }
            if (chunk.type === 'VP8 ' && chunk.data.length >= 10) {
                width = (chunk.data[6] | (chunk.data[7] << 8)) & 0x3fff;
                height = (chunk.data[8] | (chunk.data[9] << 8)) & 0x3fff;
                break;
            }
            if (chunk.type === 'VP8L' && chunk.data.length >= 5) {
                width = 1 + chunk.data[1] + ((chunk.data[2] & 0x3f) << 8);
                height = 1 + ((chunk.data[2] >> 6) | (chunk.data[3] << 2) | ((chunk.data[4] & 0x0f) << 10));
                break;
            }
        }
        if (width < 1 || height < 1) throw new Error('Unable to determine sticker WebP dimensions.');
        const random = new Uint8Array(16);
        crypto.getRandomValues(random);
        const packId = [...random].map((byte) => byte.toString(16).padStart(2, '0')).join('');
        const json = new TextEncoder().encode(JSON.stringify({
            'sticker-pack-id': packId,
            'sticker-pack-name': name ?? null,
            'sticker-pack-publisher': author ?? null,
            emojis: Array.isArray(categories) && categories.length > 0 ? categories : [''],
        }));
        const exif = new Uint8Array(22 + json.length);
        exif.set([0x49, 0x49, 0x2a, 0, 8, 0, 0, 0, 1, 0, 0x41, 0x57, 7, 0], 0);
        exif.set(write32(json.length), 14);
        exif.set([0x16, 0, 0, 0], 18);
        exif.set(json, 22);
        const encodeChunk = (type, data) => {
            const result = new Uint8Array(8 + data.length + (data.length % 2));
            result.set([...type].map((character) => character.charCodeAt(0)), 0);
            result.set(write32(data.length), 4);
            result.set(data, 8);
            return result;
        };
        const outputChunks = [];
        let extended = false;
        for (const chunk of chunks) {
            if (chunk.type === 'EXIF') continue;
            if (chunk.type === 'VP8X') {
                chunk.data[0] |= 0x08;
                extended = true;
            }
            outputChunks.push(encodeChunk(chunk.type, chunk.data));
        }
        if (!extended) {
            outputChunks.unshift(encodeChunk('VP8X', new Uint8Array([
                0x08, 0, 0, 0, ...write24(width - 1), ...write24(height - 1),
            ])));
        }
        outputChunks.push(encodeChunk('EXIF', exif));
        const bodyLength = outputChunks.reduce((total, chunk) => total + chunk.length, 0);
        const output = new Uint8Array(12 + bodyLength);
        output.set([0x52, 0x49, 0x46, 0x46, ...write32(bodyLength + 4), 0x57, 0x45, 0x42, 0x50]);
        let outputOffset = 12;
        for (const chunk of outputChunks) {
            output.set(chunk, outputOffset);
            outputOffset += chunk.length;
        }
        let binary = '';
        for (let index = 0; index < output.length; index += 0x8000) {
            binary += String.fromCharCode(...output.subarray(index, index + 0x8000));
        }
        return btoa(binary);
    };
    const contentType = (type) => {
        if (type === 'chat') return CONTENT.TEXT;
        if (['image', 'video', 'audio', 'ptt', 'document', 'sticker'].includes(type)) return CONTENT.MEDIA;
        if (type === 'location') return CONTENT.LOCATION;
        if (type === 'vcard' || type === 'multi_vcard') return CONTENT.CONTACT;
        if (type === 'poll_creation') return CONTENT.POLL;
        if (type === 'order') return 8;
        if (type === 'payment') return 9;
        if (type === 'gp2' || type === 'notification_template') return CONTENT.SYSTEM;
        return CONTENT.UNKNOWN;
    };
    const messageType = (type) => Object.freeze({
        album: 1,
        audio: 2,
        broadcast_notification: 3,
        buttons_response: 4,
        call_log: 5,
        ciphertext: 6,
        vcard: 7,
        multi_vcard: 8,
        debug: 9,
        document: 10,
        e2e_notification: 11,
        gp2: 12,
        group_invite: 13,
        group_notification: 14,
        hsm: 15,
        image: 16,
        interactive: 17,
        list: 18,
        list_response: 19,
        location: 20,
        native_flow: 21,
        notification: 22,
        notification_template: 23,
        order: 24,
        oversized: 25,
        payment: 26,
        poll_creation: 27,
        product: 28,
        protocol: 29,
        reaction: 30,
        revoked: 31,
        scheduled_event_creation: 32,
        sticker: 33,
        template_button_reply: 34,
        chat: 35,
        unknown: 36,
        video: 37,
        ptt: 38,
    })[type] ?? 36;
    const messageData = (message) => {
        const from = serialized(message.from);
        const to = serialized(message.to);
        const fromMe = Boolean(message.id?.fromMe);
        const ackValues = Object.freeze({ '-1': 1, '0': 2, '1': 3, '2': 4, '3': 5, '4': 6 });
        const internalId = typeof message.id?.id === 'string' ? message.id.id : '';
        const deviceType = internalId.length > 25 ? 1 : internalId.startsWith('3A') ? 2 : 3;
        const locationDescription = typeof message.loc === 'string' ? message.loc.split('\n') : [];
        const body = message.caption ?? message.body ?? message.pollName ?? message.eventName ?? '';
        return {
            id: serialized(message.id),
            messageId: messageIdData(message.id),
            chatId: fromMe ? to : from,
            from,
            to,
            body: typeof body === 'string' ? body : '',
            fromMe,
            timestamp: Number.isInteger(message.t) ? message.t : Math.floor(Date.now() / 1000),
            type: messageType(message.type),
            contentType: contentType(message.type),
            ack: ackValues[String(message.ack)] ?? 2,
            mediaKey: typeof message.mediaKey === 'string' ? message.mediaKey : null,
            hasMedia: Boolean(message.directPath),
            author: serialized(message.author) || null,
            deviceType,
            isForwarded: Boolean(message.isForwarded),
            forwardingScore: Number.isInteger(message.forwardingScore) ? message.forwardingScore : 0,
            isStatus: Boolean(message.isStatusV3 || message.id?.remote === 'status@broadcast'),
            isStarred: Boolean(message.star),
            broadcast: Boolean(message.broadcast),
            hasQuotedMsg: Boolean(message.quotedMsg),
            hasReaction: Boolean(message.hasReaction),
            duration: Number.isInteger(message.duration) ? message.duration : null,
            location: message.type === 'location' ? {
                latitude: message.lat,
                longitude: message.lng,
                name: locationDescription[0] ?? null,
                address: locationDescription[1] ?? null,
                url: message.clientUrl ?? null,
            } : null,
            vCards: message.type === 'multi_vcard'
                ? (message.vcardList ?? []).map((card) => card.vcard)
                : message.type === 'vcard' ? [message.body] : [],
            mentionedIds: (message.mentionedJidList ?? []).map(serialized),
            groupMentions: (message.groupMentions ?? []).map((mention) => ({
                groupSubject: mention.groupSubject ?? '',
                groupJid: serialized(mention.groupJid),
            })),
            isGif: Boolean(message.isGif),
            isEphemeral: Boolean(message.isEphemeral),
            title: message.title ?? null,
            description: message.description ?? null,
            businessOwnerJid: serialized(message.businessOwnerJid) || null,
            productId: message.productId ?? null,
            orderId: message.orderId ?? null,
            token: message.token ?? null,
            latestEditSenderTimestampMs: Number.isInteger(message.latestEditSenderTimestampMs)
                ? message.latestEditSenderTimestampMs : null,
            latestEditMsgKey: messageIdData(message.latestEditMsgKey),
            protocolMessageKey: messageIdData(message.protocolMessageKey),
            links: Array.isArray(message.links) ? message.links : [],
            dynamicReplyButtons: Array.isArray(message.dynamicReplyButtons) ? message.dynamicReplyButtons : [],
            selectedButtonId: message.selectedButtonId ?? null,
            selectedRowId: message.listResponse?.singleSelectReply?.selectedRowId ?? null,
            pollName: message.pollName ?? null,
            pollOptions: (message.pollOptions ?? []).map((option) => ({ name: option.name, localId: option.localId })),
            allowMultipleAnswers: Boolean(!message.pollSelectableOptionsCount),
            messageSecret: message.messageSecret ? Object.values(message.messageSecret) : [],
            eventDescription: message.eventDescription ?? null,
            eventStartTime: message.eventStartTime ?? null,
            eventEndTime: message.eventEndTime ?? null,
            eventLocation: message.eventLocation ?? null,
            eventJoinLink: message.eventJoinLink ?? null,
            isEventCanceled: Boolean(message.isEventCanceled),
            inviteV4: message.inviteCode ? {
                inviteCode: message.inviteCode,
                inviteCodeExp: message.inviteCodeExp,
                groupId: serialized(message.inviteGrp),
                groupName: message.inviteGrpName ?? null,
                fromId: from,
                toId: to,
            } : null,
        };
    };
    const groupNotificationData = (message) => ({
        id: serialized(message.id),
        author: serialized(message.author),
        body: typeof message.body === 'string' ? message.body : '',
        chatId: serialized(message.from),
        recipientIds: Array.isArray(message.recipients) ? message.recipients.map(serialized) : [],
        timestamp: Number.isInteger(message.t) ? message.t : Math.floor(Date.now() / 1000),
        type: Object.freeze({
            add: 1, announce: 2, demote: 3, description: 4, invite: 5,
            leave: 6, picture: 7, promote: 8, remove: 9, restrict: 10, subject: 11,
            linked_group_join: 1,
        })[message.subtype] ?? 1,
    });
    const businessHoursData = (businessHours) => {
        if (!businessHours || typeof businessHours !== 'object') return null;
        const modes = Object.freeze({ CLOSED: 1, OPEN_24H: 2, OPEN_24_HOURS: 2, SPECIFIC_HOURS: 3 });
        const config = {};
        for (const [day, value] of Object.entries(businessHours.config ?? {})) {
            config[day] = {
                mode: modes[String(value?.mode).toUpperCase()] ?? 4,
                hours: Array.isArray(value?.hours) ? value.hours.filter(Number.isInteger) : [],
            };
        }
        return { config, timezone: String(businessHours.timezone ?? '') };
    };
    const businessProfileData = (profile) => {
        if (!profile || typeof profile !== 'object') return null;
        return {
            id: {
                server: profile.id?.server ?? '',
                user: profile.id?.user ?? '',
                _serialized: serialized(profile.id),
            },
            tag: String(profile.tag ?? ''),
            description: String(profile.description ?? ''),
            categories: Array.isArray(profile.categories) ? profile.categories.map((category) => ({
                id: String(category.id ?? ''),
                localized_display_name: String(category.localized_display_name ?? ''),
            })) : [],
            profileOptions: profile.profileOptions ?? {},
            email: String(profile.email ?? ''),
            website: Array.isArray(profile.website) ? profile.website.map(String) : [],
            latitude: Number(profile.latitude ?? 0),
            longitude: Number(profile.longitude ?? 0),
            businessHours: businessHoursData(profile.businessHours),
            address: String(profile.address ?? ''),
            fbPage: profile.fbPage ?? {},
            ifProfileLinked: profile.ifProfileLinked === true,
            coverPhoto: profile.coverPhoto ?? null,
        };
    };
    const contactData = (contact) => {
        const typeValues = Object.freeze({ in: 1, out: 2 });
        const verifiedValues = Object.freeze({ unknown: 1, low: 2, high: 3 });
        return {
            id: serialized(contact.id),
            number: contact.userid ?? contact.id?.user ?? '',
            name: contact.name ?? '',
            pushname: contact.pushname ?? '',
            isBusiness: Boolean(contact.isBusiness),
            isEnterprise: Boolean(contact.isEnterprise),
            isGroup: Boolean(contact.isGroup),
            isMe: Boolean(contact.isMe),
            isMyContact: Boolean(contact.isMyContact),
            isUser: Boolean(contact.isUser),
            isWAContact: Boolean(contact.isWAContact),
            isBlocked: Boolean(contact.isBlocked),
            labels: Array.isArray(contact.labels) ? contact.labels.map(String) : [],
            sectionHeader: typeof contact.sectionHeader === 'string' ? contact.sectionHeader : null,
            shortName: typeof contact.shortName === 'string' ? contact.shortName : null,
            statusMute: Boolean(contact.statusMute),
            type: typeValues[String(contact.type).toLowerCase()] ?? 3,
            verifiedLevel: verifiedValues[String(contact.verifiedLevel).toLowerCase()] ?? 1,
            verifiedName: typeof contact.verifiedName === 'string' ? contact.verifiedName : null,
            businessProfile: businessProfileData(contact.businessProfile),
        };
    };
    const compatibleChatModel = async (chat, options = {}) => {
        const key = chat?.lastReceivedKey;
        let patchedKey = false;
        if (key && typeof key === 'object' && typeof key._serialized !== 'string') {
            Object.defineProperty(key, '_serialized', {
                configurable: true,
                value: serialized(key),
            });
            patchedKey = true;
        }
        try {
            return await globalThis.WWebJS.getChatModel(chat, options);
        } finally {
            if (patchedKey) delete key._serialized;
        }
    };
    const compatibleChatData = async (chat, options = {}) => {
        const model = await compatibleChatModel(chat, options);
        if (model?.lastMessage) model.lastMessage = messageData(model.lastMessage);
        return model;
    };
    const broadcastData = (broadcast) => ({
        id: {
            server: broadcast.id?.server ?? '',
            user: broadcast.id?.user ?? '',
            _serialized: serialized(broadcast.id),
        },
        timestamp: Number.isInteger(broadcast.t) ? broadcast.t : 0,
        totalCount: Number.isInteger(broadcast.totalCount) ? broadcast.totalCount : 0,
        unreadCount: Number.isInteger(broadcast.unreadCount) ? broadcast.unreadCount : 0,
        msgs: (broadcast.msgs ?? []).map(messageData),
    });
    const connectionState = (state) => Object.freeze({
        CONNECTED: 1,
        OPENING: 2,
        PAIRING: 3,
        TIMEOUT: 4,
        UNLAUNCHED: 5,
        UNPAIRED: 6,
        UNPAIRED_IDLE: 7,
        CONFLICT: 8,
        DEPRECATED_VERSION: 9,
        PROXYBLOCK: 10,
        TOS_BLOCK: 11,
        SMB_TOS_BLOCK: 12,
    })[state] ?? 13;

    const install = async () => {
        for (let attempt = 0; attempt < 1200; attempt += 1) {
            if (globalThis.Debug?.VERSION !== undefined && typeof globalThis.require === 'function') {
                try {
                    const socketModel = globalThis.require('WAWebSocketModel');
                    const connModel = globalThis.require('WAWebConnModel');
                    const collections = globalThis.require('WAWebCollections');
                    if (socketModel?.Socket && connModel?.Conn && collections?.Msg && collections?.Chat) break;
                } catch {
                    // WhatsApp Web exposes its module loader before all module aliases are ready.
                }
            }
            await sleep(50);
        }
        if (globalThis.Debug?.VERSION === undefined || typeof globalThis.require !== 'function') {
            throw new Error('WhatsApp Web modules did not become available.');
        }
        const socketModel = globalThis.require('WAWebSocketModel');
        const connModel = globalThis.require('WAWebConnModel');
        const collections = globalThis.require('WAWebCollections');
        if (!socketModel?.Socket || !connModel?.Conn || !collections?.Msg || !collections?.Chat) {
            throw new Error(`WhatsApp Web core modules did not become available (version ${globalThis.Debug.VERSION}).`);
        }

        const { Socket } = socketModel;
        const { Conn } = connModel;
        const { Msg, Chat } = collections;
        const lookupMessage = async (messageId) => Msg.get(messageId)
            ?? (await Msg.getMessagesById([messageId]))?.messages?.[0]
            ?? null;
        if (clientOptions.deviceName || clientOptions.browserName) {
            const browser = globalThis.require('WAWebMiscBrowserUtils');
            const originalInfo = browser.info;
            browser.info = () => ({
                ...originalInfo(),
                ...(clientOptions.deviceName ? { os: clientOptions.deviceName } : {}),
                ...(clientOptions.browserName ? { name: clientOptions.browserName } : {}),
            });
        }

        let qrRetries = 0;
        const emitQr = async (reference) => {
            if (reference == null) return;
            if (clientOptions.pairWithPhoneNumber?.phoneNumber) return;
            const registration = await globalThis
                .require('WAWebSignalStoreApi')
                .waSignalStore.getRegistrationInfo();
            const noise = await globalThis.require('WAWebUserPrefsInfoStore').waNoiseInfo.get();
            const base64 = globalThis.require('WABase64');
            const secret = await globalThis.require('WAWebUserPrefsMultiDevice').getADVSecretKey();
            const platform = globalThis.require('WAWebCompanionRegClientUtils').DEVICE_PLATFORM;
            emit(EVENT.QR_CODE, {
                code: [
                    reference,
                    base64.encodeB64(noise.staticKeyPair.pubKey),
                    base64.encodeB64(registration.identityKeyPair.pubKey),
                    secret,
                    platform,
                ].join(','),
            });
            qrRetries += 1;
            if (clientOptions.qrMaxRetries > 0 && qrRetries > clientOptions.qrMaxRetries) {
                emit(EVENT.AUTHENTICATION_FAILURE, { message: 'QR retry limit reached.' });
                emit(EVENT.DISCONNECTED, { reason: DISCONNECT_REASON.QR_RETRY_LIMIT });
                await Socket.logout();
            }
        };
        const requestPairingCode = async () => {
            const pairing = clientOptions.pairWithPhoneNumber;
            if (!pairing?.phoneNumber) return;
            const api = globalThis.require('WAWebAltDeviceLinkingApi');
            const getCode = async () => {
                api.setPairingType('ALT_DEVICE_LINKING');
                await api.initializeAltDeviceLinking();
                const code = await api.startAltLinkingFlow(pairing.phoneNumber, pairing.showNotification !== false);
                emit(EVENT.PAIRING_CODE_RECEIVED, { code });
            };
            await getCode();
            globalThis.__pamPairingCodeInterval = setInterval(async () => {
                if (!['UNPAIRED', 'UNPAIRED_IDLE'].includes(Socket.state)) {
                    clearInterval(globalThis.__pamPairingCodeInterval);
                    return;
                }
                await getCode();
            }, pairing.intervalMs ?? 180000);
        };

        let authenticated = false;
        const ready = async () => {
            if (typeof globalThis.WWebJS === 'undefined') {
                globalThis.__pamLoadWWebJs();
            }
            const timestamp = Math.floor(Date.now() / 1000);
            const connection = Conn.serialize();
            const wid = globalThis.require('WAWebUserPrefsMeUser').getMaybeMePnUser()
                ?? globalThis.require('WAWebUserPrefsMeUser').getMaybeMeLidUser();
            const info = {
                ...connection,
                wid: wid ? { server: wid.server, user: wid.user, _serialized: serialized(wid) } : null,
            };
            emit(EVENT.AUTHENTICATED, { timestamp });
            emit(EVENT.READY, { timestamp, info });
            authenticated = true;
        };
        const stateChanged = (_model, state) => {
            emit(EVENT.STATE_CHANGED, { state: connectionState(state) });
            if (state === 'UNPAIRED' || state === 'UNPAIRED_IDLE') {
                if (authenticated) emit(EVENT.DISCONNECTED, { reason: DISCONNECT_REASON.LOGOUT });
                return;
            }
            if (state === 'CONFLICT') {
                if (clientOptions.takeoverOnConflict) {
                    setTimeout(() => Socket.takeover(), clientOptions.takeoverTimeoutMs ?? 0);
                    return;
                }
                emit(EVENT.DISCONNECTED, { reason: DISCONNECT_REASON.CONFLICT });
            } else if (state === 'UNLAUNCHED') {
                emit(EVENT.DISCONNECTED, { reason: DISCONNECT_REASON.UNLAUNCHED });
            }
        };
        const ciphertextTimers = new Map();
        const mediaStreams = new Map();
        const emitCreatedMessage = (message) => {
            if (!message.isNewMsg) return;
            emit(EVENT.MESSAGE_CREATED, { message: messageData(message) });
            if (message.id?.fromMe) return;
            emit(EVENT.MESSAGE_RECEIVED, messageData(message));
        };
        const messageAdded = (message) => {
            if (!message.isNewMsg) return;
            if (message.type === 'reaction') emitReaction(message);
            if (message.type === 'gp2') {
                const payload = groupNotificationData(message);
                if (['add', 'invite', 'linked_group_join'].includes(message.subtype)) {
                    emit(EVENT.GROUP_JOINED, payload);
                } else if (['remove', 'leave'].includes(message.subtype)) {
                    emit(EVENT.GROUP_LEFT, payload);
                } else if (['promote', 'demote'].includes(message.subtype)) {
                    emit(EVENT.GROUP_ADMIN_CHANGED, payload);
                } else if (message.subtype === 'membership_approval_request') {
                    emit(EVENT.GROUP_MEMBERSHIP_REQUEST, payload);
                } else {
                    emit(EVENT.GROUP_UPDATED, payload);
                }
                return;
            }
            if (message.type !== 'ciphertext') {
                emitCreatedMessage(message);
                return;
            }
            emit(EVENT.MESSAGE_CIPHERTEXT, { message: messageData(message) });
            if (message.subtype?.endsWith('_unavailable_fanout')) return;
            globalThis.require('WAWebNonMessageDataRequestPlaceholderMessageResendUtils')
                .handlePlaceholderMsgsSeen([message], true);
            const timer = setTimeout(() => {
                ciphertextTimers.delete(serialized(message.id));
                if (message.type === 'ciphertext') {
                    emit(EVENT.MESSAGE_CIPHERTEXT_FAILED, { message: messageData(message) });
                }
            }, 15000);
            ciphertextTimers.set(serialized(message.id), timer);
        };
        const messageAcknowledged = (message, ack) => {
            const ackMap = Object.freeze({ '-1': 1, '0': 2, '1': 3, '2': 4, '3': 5, '4': 6 });
            emit(EVENT.MESSAGE_ACKNOWLEDGED, { message: messageData(message), ack: ackMap[String(ack)] ?? 1 });
        };
        const contactChanged = (message) => {
            const participantChanged = message.type === 'gp2' && message.subtype === 'modify';
            const contactChanged = message.type === 'notification_template' && message.subtype === 'change_number';
            if (!participantChanged && !contactChanged) return;
            const newWid = participantChanged ? message.recipients?.[0] : message.to;
            const oldWid = participantChanged
                ? message.author
                : message.templateParams?.find((wid) => serialized(wid) !== serialized(newWid));
            emit(EVENT.CONTACT_CHANGED, {
                message: messageData(message),
                oldId: serialized(oldWid),
                newId: serialized(newWid),
                isContact: contactChanged,
            });
        };
        const messageEdited = (message, body, previousBody) => {
            if (message.type === 'revoked') return;
            emit(EVENT.MESSAGE_EDITED, { message: messageData(message), body, previousBody });
        };
        const messageTypeChanged = (message) => {
            if (message.type === 'revoked') {
                emit(EVENT.MESSAGE_REVOKED_EVERYONE, { message: messageData(message) });
                return;
            }
            const id = serialized(message.id);
            const timer = ciphertextTimers.get(id);
            if (timer !== undefined) {
                clearTimeout(timer);
                ciphertextTimers.delete(id);
                emitCreatedMessage(message);
            }
        };
        const messageRevokedMe = (message) => {
            if (message.isNewMsg) emit(EVENT.MESSAGE_REVOKED_ME, { message: messageData(message) });
        };
        const emittedMediaUploads = new Set();
        const emitMediaUploaded = (message, unsent = false) => {
            const id = serialized(message?.id);
            if (!message?.id?.fromMe || unsent || !id || emittedMediaUploads.has(id)) return;
            emittedMediaUploads.add(id);
            emit(EVENT.MEDIA_UPLOADED, { message: messageData(message) });
        };
        const emittedReactions = new Set();
        const emitReaction = (reaction) => {
            const reactionId = reaction?.id ?? reaction?.msgKey;
            const id = serialized(reactionId);
            const parent = reaction?.reactionParentKey ?? reaction?.parentMsgKey;
            const deduplicationKey = id + ':' + serialized(parent);
            if (!id || emittedReactions.has(deduplicationKey)) return;
            emittedReactions.add(deduplicationKey);
            const ackMap = Object.freeze({ '-1': 1, '0': 2, '1': 3, '2': 4, '3': 5, '4': 6 });
            const sender = reaction.author ?? reaction.from ?? reaction.senderUserJid;
            emit(EVENT.MESSAGE_REACTION, { reaction: {
                id: messageIdData(reactionId),
                orphan: Number.isInteger(reaction.orphan) ? reaction.orphan : 0,
                orphanReason: reaction.orphanReason ?? null,
                timestamp: Number.isInteger(reaction.reactionTimestamp)
                    ? Math.floor(reaction.reactionTimestamp / 1000)
                    : Number.isInteger(reaction.t) ? reaction.t : 0,
                reaction: String(reaction.reactionText ?? reaction.reaction ?? reaction.body ?? ''),
                read: reaction.read === true,
                msgId: messageIdData(parent),
                senderId: serialized(sender),
                ack: Number.isInteger(reaction.ack) ? (ackMap[String(reaction.ack)] ?? null) : null,
            }});
        };
        const chatArchived = (chat, archived, previousArchived) => {
            emit(EVENT.CHAT_ARCHIVED, {
                chatId: serialized(chat.id),
                archived: Boolean(archived),
                previousArchived: Boolean(previousArchived),
            });
        };
        const chatRemoved = (chat) => emit(EVENT.CHAT_REMOVED, { chatId: serialized(chat.id) });
        const unreadChanged = (chat, unreadCount) => {
            emit(EVENT.UNREAD_COUNT_CHANGED, { chatId: serialized(chat.id), unreadCount });
        };
        const batteryChanged = (state) => {
            if (Number.isInteger(state?.battery)) {
                emit(EVENT.BATTERY_CHANGED, { battery: state.battery, plugged: Boolean(state.plugged) });
            }
        };
        const Cmd = globalThis.require('WAWebCmd').Cmd;
        const loadingProgress = () => {
            const handler = globalThis.require('WAWebOfflineHandler').OfflineMessageHandler;
            const percent = handler.getOfflineDeliveryProgress?.();
            if (Number.isInteger(percent)) emit(EVENT.LOADING_SCREEN, { percent, message: 'WhatsApp' });
        };

        const listeners = [
            [Socket, 'change:hasSynced', ready],
            [Socket, 'change:state', stateChanged],
            [Conn, 'change:ref', (_model, reference) => void emitQr(reference)],
            [Conn, 'change:battery', batteryChanged],
            [Cmd, 'offline_progress_update_from_bridge', loadingProgress],
            [Msg, 'add', messageAdded],
            [Msg, 'change', contactChanged],
            [Msg, 'change:ack', messageAcknowledged],
            [Msg, 'change:type', messageTypeChanged],
            [Msg, 'remove', messageRevokedMe],
            [Msg, 'change:body change:caption', messageEdited],
            [Msg, 'change:isUnsentMedia', emitMediaUploaded],
            [Chat, 'change:archive', chatArchived],
            [Chat, 'remove', chatRemoved],
            [Chat, 'change:unreadCount', unreadChanged],
        ];
        for (const [model, event, listener] of globalThis.__pamWhatsAppListeners ?? []) {
            model.off(event, listener);
        }
        for (const [model, event, listener] of listeners) model.on(event, listener);
        globalThis.__pamWhatsAppListeners = listeners;
        try {
            globalThis.WWebJS.injectToFunction({
                module: 'WAWebAddonReactionTableMode',
                function: 'reactionTableMode.bulkUpsert',
            }, (module, original, ...args) => {
                for (const reaction of args[0] ?? []) emitReaction(reaction);
                return original.apply(module, args);
            });
        } catch (_error) {
            // Reaction storage is not available in every account/build.
        }
        try {
            globalThis.WWebJS.injectToFunction({
                module: 'WAWebAddonPollVoteTableMode',
                function: 'pollVoteTableMode.bulkUpsert',
            }, (module, original, ...args) => {
                void Promise.all((args[0] ?? []).map(async (vote) => {
                    const parent = await lookupMessage(serialized(vote.pollUpdateParentKey));
                    if (!parent) return;
                    const selectedIds = Array.from(new Uint8Array(vote.selectedOptionLocalIds ?? []));
                    emit(EVENT.VOTE_UPDATED, { vote: {
                        voter: serialized(vote.author ?? vote.from),
                        selectedOptions: selectedIds.map((id) => ({
                            id,
                            name: parent.pollOptions?.find((option) => option.localId === id)?.name ?? '',
                        })),
                        interractedAtTs: Number.isInteger(vote.t) ? vote.t * 1000 : 0,
                        parentMessage: messageData(parent),
                    }});
                }));
                return original.apply(module, args);
            });
        } catch (_error) {
            // Poll vote storage is not available in every account/build.
        }
        void requestPairingCode();

        try {
            const calls = globalThis.require('WAWebCallCollection');
            const mapKey = Object.keys(calls).find((key) => calls[key] instanceof Map);
            const callMap = mapKey === undefined ? null : calls[mapKey];
            if (callMap instanceof Map && callMap.__pamOriginalSet === undefined) {
                const originalSet = callMap.set.bind(callMap);
                Object.defineProperty(callMap, '__pamOriginalSet', { value: originalSet });
                callMap.set = (key, call) => {
                    emit(EVENT.CALL_RECEIVED, {
                        id: String(call.id ?? ''),
                        peerId: serialized(call.peerJid),
                        timestamp: Number.isInteger(call.offerTime) ? call.offerTime : 0,
                        isVideo: Boolean(call.isVideo),
                        isGroup: Boolean(call.isGroup),
                        canHandleLocally: Boolean(call.canHandleLocally),
                        outgoing: Boolean(call.outgoing),
                        webClientShouldHandle: Boolean(call.webClientShouldHandle),
                        participantIds: Array.from(call.participants ?? [], (participant) => serialized(participant?.jid ?? participant)),
                    });
                    return originalSet(key, call);
                };
            }
        } catch (_error) {
            // Calls are not available in every WhatsApp Web build/account mode.
        }

        globalThis.PamWhatsApp = Object.freeze({
            invoke: async (method, args) => {
                const getChatModel = async (chatId) => await globalThis.WWebJS.getChat(chatId, { getAsModel: false });
                const getMessageModel = async (messageId) => Msg.get(messageId)
                    ?? (await Msg.getMessagesById([messageId]))?.messages?.[0]
                    ?? null;
                const allowed = new Set([
                    'getChats',
                    'getChannels',
                    'getContacts',
                    'getChatById',
                    'getContactById',
                    'getWWebVersion',
                    'getState',
                    'sendSeen',
                    'getLabels',
                    'getChatLabels',
                    'archiveChat',
                    'unarchiveChat',
                    'pinChat',
                    'unpinChat',
                    'muteChat',
                    'unmuteChat',
                    'markChatUnread',
                    'getProfilePicUrl',
                    'getNumberId',
                    'getFormattedNumber',
                    'getCountryCode',
                    'sendPresenceAvailable',
                    'sendPresenceUnavailable',
                    'setStatus',
                    'setDisplayName',
                    'syncHistory',
                    'clearMessages',
                    'deleteChat',
                    'fetchMessages',
                    'sendChatstate',
                    'getChatsByLabelId',
                    'getPinnedMessages',
                    'addOrRemoveLabels',
                    'addOrEditCustomerNote',
                    'getCustomerNote',
                    'modifyGroupParticipants',
                    'setGroupSubject',
                    'setGroupDescription',
                    'setGroupSetting',
                    'deleteGroupPicture',
                    'setGroupPicture',
                    'getGroupInviteCode',
                    'revokeGroupInvite',
                    'leaveGroup',
                    'addGroupParticipants',
                    'getGroupMembershipRequests',
                    'membershipRequestAction',
                    'getQuotedMessage',
                    'forwardMessage',
                    'downloadMessageMedia',
                    'openMessageMediaStream',
                    'readMessageMediaStream',
                    'closeMessageMediaStream',
                    'deleteMessage',
                    'starMessage',
                    'pinMessage',
                    'reactMessage',
                    'editMessage',
                    'voteMessage',
                    'getMessageInfo',
                    'reloadMessage',
                    'acceptGroupV4Invite',
                    'editScheduledEvent',
                    'getMessageOrder',
                    'getMessagePayment',
                    'getMessageReactions',
                    'getPollVotes',
                    'getProductMetadata',
                    'blockContact',
                    'getContactAbout',
                    'getCommonGroups',
                    'getBroadcastById',
                    'getBroadcasts',
                    'getBlockedContacts',
                    'getChannelSubscribers',
                    'setChannelMetadata',
                    'setChannelReactionSetting',
                    'muteChannel',
                    'sendChannelAdminInvite',
                    'acceptChannelAdminInvite',
                    'revokeChannelAdminInvite',
                    'demoteChannelAdmin',
                    'transferChannelOwnership',
                    'fetchChannelMessages',
                    'deleteChannel',
                    'getChannelByInviteCode',
                    'createChannel',
                    'subscribeToChannel',
                    'unsubscribeFromChannel',
                    'searchChannels',
                    'rejectCall',
                    'getBatteryStatus',
                    'createGroup',
                    'getInviteInfo',
                    'acceptInvite',
                    'requestPairingCode',
                    'cancelPairingCode',
                    'resetState',
                    'createCallLink',
                    'sendResponseToScheduledEvent',
                    'sendReaction',
                    'searchMessages',
                    'getMessageById',
                    'getLabelById',
                    'setProfilePicture',
                    'deleteProfilePicture',
                    'revokeStatusMessage',
                    'setAutoDownload',
                    'setBackgroundSync',
                    'getContactDeviceCount',
                    'saveOrEditAddressbookContact',
                    'deleteAddressbookContact',
                    'getContactLidAndPhone',
                    'openChatWindow',
                    'openChatDrawer',
                    'openChatSearch',
                    'openChatWindowAt',
                    'openMessageDrawer',
                    'closeRightDrawer',
                    'getFeatures',
                    'checkFeatureStatus',
                    'enableFeatures',
                    'disableFeatures',
                ]);
                if (!allowed.has(method) || !Array.isArray(args)) {
                    throw new Error('Unsupported PAM WhatsApp bridge invocation.');
                }
                if (method === 'getWWebVersion') return globalThis.Debug.VERSION;
                if (method === 'getState') {
                    return connectionState(Socket.state);
                }
                if (method === 'getBatteryStatus') {
                    return {
                        battery: Number.isInteger(Conn.battery) ? Conn.battery : 0,
                        plugged: Conn.plugged === true,
                    };
                }
                if (method === 'openChatWindow' || method === 'openChatDrawer' || method === 'openChatSearch') {
                    const chat = await getChatModel(args[0]);
                    if (!chat) throw new Error('Chat was not found.');
                    const Cmd = globalThis.require('WAWebCmd').Cmd;
                    if (method === 'openChatWindow') return await Cmd.openChatBottom({ chat });
                    if (method === 'openChatDrawer') return await Cmd.openDrawerMid(chat);
                    return await Cmd.chatSearch(chat);
                }
                if (method === 'openChatWindowAt' || method === 'openMessageDrawer') {
                    const messageId = args[0];
                    const collection = globalThis.require('WAWebCollections');
                    const message = collection.Msg.get(messageId)
                        ?? (await collection.Msg.getMessagesById([messageId]))?.messages?.[0];
                    if (!message) throw new Error('Message was not found.');
                    const Cmd = globalThis.require('WAWebCmd').Cmd;
                    if (method === 'openMessageDrawer') return await Cmd.msgInfoDrawer(message);
                    const chat = collection.Chat.get(message.id.remote)
                        ?? await collection.Chat.find(message.id.remote);
                    if (!chat) throw new Error('Message chat was not found.');
                    const context = await globalThis.require('WAWebChatMessageSearch')
                        .getSearchContext(chat, message.id);
                    return await Cmd.openChatAt({ chat, msgContext: context });
                }
                if (method === 'closeRightDrawer') {
                    return await globalThis.require('WAWebDrawerManager').DrawerManager.closeDrawerRight();
                }
                if (method === 'getFeatures' || method === 'checkFeatureStatus'
                    || method === 'enableFeatures' || method === 'disableFeatures') {
                    const features = globalThis.require('WAWebCollections').Features;
                    if (!features) throw new Error('This version of WhatsApp Web does not support features.');
                    if (method === 'getFeatures') return features.F;
                    if (method === 'checkFeatureStatus') return features.supportsFeature(args[0]);
                    const enabled = method === 'enableFeatures';
                    for (const feature of args[0]) features.setFeature(feature, enabled);
                    return null;
                }
                if (method === 'getInviteInfo') {
                    return await globalThis.require('WAWebGroupQueryJob').queryGroupInvite(args[0]);
                }
                if (method === 'acceptInvite') {
                    const result = await globalThis.require('WAWebGroupInviteJob').joinGroupViaInvite(args[0]);
                    return serialized(result.gid);
                }
                if (method === 'requestPairingCode') {
                    const api = globalThis.require('WAWebAltDeviceLinkingApi');
                    const getCode = async () => {
                        api.setPairingType('ALT_DEVICE_LINKING');
                        await api.initializeAltDeviceLinking();
                        const code = await api.startAltLinkingFlow(args[0], args[1] !== false);
                        emit(EVENT.PAIRING_CODE_RECEIVED, { code });
                        return code;
                    };
                    if (globalThis.__pamPairingCodeInterval) clearInterval(globalThis.__pamPairingCodeInterval);
                    globalThis.__pamPairingCodeInterval = setInterval(async () => {
                        if (!['UNPAIRED', 'UNPAIRED_IDLE'].includes(Socket.state)) {
                            clearInterval(globalThis.__pamPairingCodeInterval);
                            return;
                        }
                        await getCode();
                    }, Number.isInteger(args[2]) ? args[2] : 180000);
                    return await getCode();
                }
                if (method === 'cancelPairingCode') {
                    if (globalThis.__pamPairingCodeInterval) {
                        clearInterval(globalThis.__pamPairingCodeInterval);
                        globalThis.__pamPairingCodeInterval = undefined;
                    }
                    globalThis.require('WAWebLaunchSocketUtils').refreshQR();
                    await globalThis.require('WAWebAltDeviceLinkingApi').initializeQRLinking();
                    return null;
                }
                if (method === 'resetState') {
                    Socket.reconnect();
                    return null;
                }
                if (method === 'createCallLink') {
                    const callTypes = Object.freeze({ 2: 'voice', 3: 'video' });
                    const callType = callTypes[args[1]];
                    if (!callType) throw new Error("Invalid call type; use Voice or Video.");
                    return await globalThis.require('WAWebGenerateEventCallLink')
                        .createEventCallLink(args[0], callType) ?? '';
                }
                if (method === 'sendResponseToScheduledEvent') {
                    const responses = Object.freeze({ 1: 0, 2: 1, 3: 2, 4: 3 });
                    const response = responses[args[0]];
                    if (response === undefined) return false;
                    const message = await getMessageModel(args[1]);
                    if (!message) return false;
                    await globalThis.require('WAWebSendEventResponseMsgAction').sendEventResponseMsg(response, message);
                    return true;
                }
                if (method === 'sendReaction') {
                    const message = await getMessageModel(args[0]);
                    if (!message) return null;
                    await globalThis.require('WAWebSendReactionMsgAction').sendReactionToMsg(message, args[1]);
                    return null;
                }
                if (method === 'searchMessages') {
                    const options = args[1] ?? {};
                    const result = await Msg.search(args[0], options.page, options.limit, options.chatId);
                    return (result.messages ?? []).map(messageData);
                }
                if (method === 'getMessageById') {
                    const message = await getMessageModel(args[0]);
                    return message ? messageData(message) : null;
                }
                if (method === 'getLabelById') {
                    return await globalThis.WWebJS.getLabel(args[0]);
                }
                if (method === 'setProfilePicture') {
                    return await globalThis.WWebJS.setPicture(args[0], args[1]);
                }
                if (method === 'deleteProfilePicture') {
                    return await globalThis.WWebJS.deletePicture(args[0]);
                }
                if (method === 'revokeStatusMessage') {
                    const status = globalThis.require('WAWebCollections').Status.getMyStatus();
                    const message = await getMessageModel(args[0]);
                    if (!status || !message) return null;
                    if (!message.id.fromMe || !message.id.remote.isStatus()) {
                        throw new Error('Only own status messages can be revoked.');
                    }
                    await globalThis.require('WAWebRevokeStatusAction').sendStatusRevokeMsgAction(status, message);
                    return null;
                }
                if (method === 'setAutoDownload') {
                    const suffixes = Object.freeze({
                        audio: 'Audio', documents: 'Documents', photos: 'Photos', videos: 'Videos',
                    });
                    const suffix = suffixes[args[0]];
                    if (!suffix) throw new Error('Unknown auto-download media type.');
                    const preferences = globalThis.require('WAWebUserPrefsGeneral');
                    if (preferences['getAutoDownload' + suffix]() !== args[1]) {
                        await preferences['setAutoDownload' + suffix](args[1]);
                    }
                    return null;
                }
                if (method === 'setBackgroundSync') {
                    const preferences = globalThis.require('WAWebUserPrefsNotifications');
                    if (preferences.getGlobalOfflineNotifications() !== args[0]) {
                        await preferences.setGlobalOfflineNotifications(args[0]);
                    }
                    return null;
                }
                if (method === 'getContactDeviceCount') {
                    const devices = await globalThis.require('WAWebApiDeviceList').getDeviceIds([
                        globalThis.require('WAWebWidFactory').createWid(args[0]),
                    ]);
                    return Array.isArray(devices?.[0]?.devices) ? devices[0].devices.length : 0;
                }
                if (method === 'saveOrEditAddressbookContact') {
                    return await globalThis.require('WAWebSaveContactAction').saveContactAction({
                        firstName: args[1], lastName: args[2], phoneNumber: args[0],
                        prevPhoneNumber: args[0], syncToAddressbook: args[3] === true, username: undefined,
                    });
                }
                if (method === 'deleteAddressbookContact') {
                    const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    return await globalThis.require('WAWebDeleteContactAction').deleteContactAction({ phoneNumber: wid });
                }
                if (method === 'getContactLidAndPhone') {
                    return await Promise.all(args[0].map(async (userId) => {
                        const result = await globalThis.WWebJS.enforceLidAndPnRetrieval(userId);
                        return { lid: serialized(result.lid), pn: serialized(result.phone) };
                    }));
                }
                if (method === 'rejectCall') {
                    return await globalThis.WWebJS.rejectCall(args[0], args[1]);
                }
                if (method === 'getChats') {
                    const chats = globalThis.require('WAWebCollections').Chat.getModelsArray();
                    return await Promise.all(chats.map((chat) => compatibleChatData(chat)));
                }
                if (method === 'getChannels') {
                    const channels = globalThis.require('WAWebCollections')
                        .WAWebNewsletterCollection.getModelsArray();
                    return await Promise.all((channels ?? []).map((channel) =>
                        compatibleChatData(channel, { isChannel: true })));
                }
                if (method === 'getChatById') {
                    const isChannel = /@\w*newsletter\b/.test(args[0]);
                    const chat = await globalThis.WWebJS.getChat(args[0], { getAsModel: false });
                    return chat ? await compatibleChatData(chat, { isChannel }) : null;
                }
                if (method === 'getChatLabels') {
                    const chat = await globalThis.WWebJS.getChat(args[0], { getAsModel: false });
                    return chat
                        ? await Promise.all((chat.labels ?? []).map((id) => globalThis.WWebJS.getLabel(id)))
                        : [];
                }
                if (method === 'getContacts') {
                    return (await globalThis.WWebJS.getContacts()).map(contactData);
                }
                if (method === 'getBlockedContacts') {
                    return (await globalThis.WWebJS.getContacts()).filter((contact) => contact.isBlocked).map(contactData);
                }
                if (method === 'getContactById') {
                    const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    const collections = globalThis.require('WAWebCollections');
                    const contact = await collections.Contact.find(wid);
                    if (contact && (contact.isBusiness || contact.isEnterprise)) {
                        try {
                            const profile = await collections.BusinessProfile.find(wid);
                            if (profile?.profileOptions) contact.businessProfile = profile;
                        } catch (_error) {
                            // Business profile hydration is optional and can race account synchronization.
                        }
                    }
                    return contact ? contactData(contact) : null;
                }
                if (method === 'blockContact') {
                    const contact = await globalThis.require('WAWebCollections').Contact.find(args[0]);
                    if (!contact || contact.isGroup) return false;
                    const resolved = globalThis.require('WAWebBlockContactUtils')
                        .getContactToBlockOnlyUseIfNoAssociatedChat(contact, 'ChatListBlock');
                    const action = globalThis.require('WAWebBlockContactAction');
                    if (args[1]) await action.blockContact({ contact: resolved });
                    else await action.unblockContact(resolved);
                    return true;
                }
                if (method === 'getContactAbout') {
                    const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    const about = await globalThis.require('WAWebContactStatusBridge').getStatus({ token: '', wid });
                    return typeof about?.status === 'string' ? about.status : null;
                }
                if (method === 'getCommonGroups') {
                    let contact = globalThis.require('WAWebCollections').Contact.get(args[0]);
                    if (!contact) {
                        const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                        const constructor = globalThis.require('WAWebCollections').Contact
                            .getModelsArray().find((item) => !item.isGroup)?.constructor;
                        if (!constructor) return [];
                        contact = new constructor({ id: wid });
                    }
                    if (!contact.commonGroups) {
                        await globalThis.require('WAWebFindCommonGroupsContactAction').findCommonGroups(contact);
                    }
                    return (contact.commonGroups?.serialize() ?? []).map((group) => ({
                        server: group.id?.server ?? '',
                        user: group.id?.user ?? '',
                        _serialized: serialized(group.id),
                    }));
                }
                if (method === 'getBroadcastById') {
                    let status = globalThis.require('WAWebCollections').Status.get(args[0]);
                    if (!status) {
                        try { status = await globalThis.require('WAWebCollections').Status.find(args[0]); }
                        catch (_error) { return null; }
                    }
                    const value = status ? globalThis.WWebJS.getStatusModel(status) : null;
                    return value ? broadcastData(value) : null;
                }
                if (method === 'getBroadcasts') {
                    return (await globalThis.WWebJS.getAllStatuses()).map(broadcastData);
                }
                if (method === 'archiveChat' || method === 'unarchiveChat') {
                    const archived = method === 'archiveChat';
                    await globalThis.require('WAWebCmd').Cmd.archiveChat(await getChatModel(args[0]), archived);
                    return archived;
                }
                if (method === 'pinChat' || method === 'unpinChat') {
                    const chat = await getChatModel(args[0]);
                    const pinned = method === 'pinChat';
                    if (Boolean(chat.pin) === pinned) return pinned;
                    if (pinned) {
                        const chats = globalThis.require('WAWebCollections').Chat.getModelsArray();
                        if (chats.length > 3 && chats[2].pin) return false;
                    }
                    await globalThis.require('WAWebCmd').Cmd.pinChat(chat, pinned);
                    return pinned;
                }
                if (method === 'muteChat' || method === 'unmuteChat') {
                    const chat = globalThis.require('WAWebCollections').Chat.get(args[0])
                        ?? await globalThis.require('WAWebCollections').Chat.find(args[0]);
                    if (method === 'muteChat') {
                        await chat.mute.mute({ expiration: args[1] ?? -1, sendDevice: true });
                    } else {
                        await chat.mute.unmute({ sendDevice: true });
                    }
                    return { isMuted: chat.mute.expiration !== 0, muteExpiration: chat.mute.expiration };
                }
                if (method === 'markChatUnread') {
                    await globalThis.require('WAWebCmd').Cmd.markChatUnread(await getChatModel(args[0]), true);
                    return null;
                }
                if (method === 'getProfilePicUrl') {
                    try {
                        const chat = await getChatModel(args[0]);
                        if (!chat) return null;
                        const picture = await globalThis.require('WAWebContactProfilePicThumbBridge')
                            .requestProfilePicFromServer(chat);
                        return picture?.eurl ?? null;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return null;
                        throw error;
                    }
                }
                if (method === 'getNumberId') {
                    const number = String(args[0]).endsWith('@c.us') ? String(args[0]) : String(args[0]) + '@c.us';
                    const wid = globalThis.require('WAWebWidFactory').createWid(number);
                    const result = await globalThis.require('WAWebQueryExistsJob').queryWidExists(wid);
                    if (!result?.wid) return null;
                    return {
                        server: result.wid.server,
                        user: result.wid.user,
                        _serialized: serialized(result.wid),
                    };
                }
                if (method === 'getFormattedNumber') {
                    let number = String(args[0]).replace('c.us', 's.whatsapp.net');
                    if (!number.includes('@s.whatsapp.net')) number += '@s.whatsapp.net';
                    return await globalThis.require('WAWebPhoneUtils').formattedPhoneNumber(number);
                }
                if (method === 'getCountryCode') {
                    const number = String(args[0]).replace(' ', '').replace('+', '').replace('@c.us', '');
                    return globalThis.require('WAPhoneFindCC').findCC(number);
                }
                if (method === 'sendPresenceAvailable') {
                    await globalThis.require('WAWebPresenceChatAction').sendPresenceAvailable();
                    return null;
                }
                if (method === 'sendPresenceUnavailable') {
                    await globalThis.require('WAWebPresenceChatAction').sendPresenceUnavailable();
                    return null;
                }
                if (method === 'setStatus') {
                    await globalThis.require('WAWebContactStatusBridge').setMyStatus(args[0]);
                    return null;
                }
                if (method === 'setDisplayName') {
                    if (!Conn.canSetMyPushname()) return false;
                    await globalThis.require('WAWebSetPushnameConnAction').setPushname(args[0]);
                    return true;
                }
                if (method === 'syncHistory') {
                    const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    const chat = globalThis.require('WAWebCollections').Chat.get(wid)
                        ?? await globalThis.require('WAWebCollections').Chat.find(wid);
                    if (chat?.endOfHistoryTransferType !== 0) return false;
                    await globalThis.require('WAWebSendNonMessageDataRequest').sendPeerDataOperationRequest(3, { chatId: chat.id });
                    return true;
                }
                if (method === 'clearMessages') return await globalThis.WWebJS.sendClearChat(args[0]);
                if (method === 'deleteChat') return await globalThis.WWebJS.sendDeleteChat(args[0]);
                if (method === 'sendChatstate') {
                    await globalThis.WWebJS.sendChatstate(args[0], args[1]);
                    return true;
                }
                if (method === 'fetchMessages') {
                    const chat = await getChatModel(args[0]);
                    const options = args[1] ?? {};
                    const filter = (message) => !message.isNotification
                        && (options.fromMe === undefined || message.id.fromMe === options.fromMe);
                    let messages = chat.msgs.getModelsArray().filter(filter);
                    if (Number.isInteger(options.limit) && options.limit > 0) {
                        while (messages.length < options.limit) {
                            const loaded = await globalThis.require('WAWebChatLoadMessages').loadEarlierMsgs({ chat });
                            if (!loaded?.length) break;
                            messages = [...loaded.filter(filter), ...messages];
                        }
                        messages.sort((left, right) => left.t > right.t ? 1 : -1);
                        messages = messages.slice(-options.limit);
                    }
                    return messages.map(messageData);
                }
                if (method === 'getChatsByLabelId') {
                    const label = globalThis.require('WAWebCollections').Label.get(args[0]);
                    if (!label) return [];
                    const ids = label.labelItemCollection.getModelsArray()
                        .filter((item) => item.parentType === 'Chat')
                        .map((item) => item.parentId);
                    return await Promise.all(ids.map((id) => globalThis.WWebJS.getChat(id)));
                }
                if (method === 'getPinnedMessages') {
                    const chatWid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    const rows = await globalThis.require('WAWebPinInChatSchema')
                        .getTable().equals(['chatId'], chatWid.toString());
                    const messages = (await Promise.all(rows.filter((row) => row.pinType === 1).map(async (row) => {
                        const result = await globalThis.require('WAWebCollections').Msg.getMessagesById([row.parentMsgKey]);
                        return result?.messages?.[0] ?? null;
                    }))).filter(Boolean);
                    return messages.map(messageData);
                }
                if (method === 'addOrRemoveLabels') {
                    if (!['smba', 'smbi'].includes(Conn.platform)) throw new Error('[LT01] Only Whatsapp business');
                    const labels = (await globalThis.WWebJS.getLabels()).filter((label) => args[0].some((id) => String(id) === String(label.id)));
                    const chats = Chat.filter((chat) => args[1].includes(serialized(chat.id)));
                    const actions = labels.map((label) => ({ id: label.id, type: 'add' }));
                    for (const chat of chats) {
                        for (const id of chat.labels ?? []) {
                            if (!actions.some((action) => String(action.id) === String(id))) actions.push({ id, type: 'remove' });
                        }
                    }
                    await globalThis.require('WAWebCollections').Label.addOrRemoveLabels(actions, chats);
                    return null;
                }
                if (method === 'addOrEditCustomerNote') {
                    const gating = globalThis.require('WAWebBizGatingUtils');
                    if (typeof gating?.smbNotesV1Enabled !== 'function' || !gating.smbNotesV1Enabled()) return null;
                    const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    const jid = globalThis.require('WAWebWidToJid').widToUserJid(wid);
                    await globalThis.require('WAWebNoteAction').noteAddAction('unstructured', jid, args[1]);
                    return null;
                }
                if (method === 'getCustomerNote') {
                    const gating = globalThis.require('WAWebBizGatingUtils');
                    if (typeof gating?.smbNotesV1Enabled !== 'function' || !gating.smbNotesV1Enabled()) return null;
                    const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    const jid = globalThis.require('WAWebWidToJid').widToUserJid(wid);
                    const note = await globalThis.require('WAWebNoteAction').retrieveOnlyNoteForChatJid(jid);
                    const value = note?.serialize();
                    if (!value) return null;
                    return {
                        chatId: globalThis.require('WAWebJidToWid').userJidToUserWid(value.chatJid)._serialized,
                        content: value.content ?? '',
                        createdAt: value.createdAt ?? 0,
                        id: value.id ?? '',
                        modifiedAt: value.modifiedAt ?? 0,
                        type: value.type === 'unstructured' ? 1 : 2,
                    };
                }
                if (method === 'modifyGroupParticipants') {
                    const chat = await getChatModel(args[0]);
                    const participants = (await Promise.all(args[2].map(async (id) => {
                        const pair = await globalThis.WWebJS.enforceLidAndPnRetrieval(id);
                        return chat.groupMetadata.participants.get(pair.lid?._serialized)
                            ?? chat.groupMetadata.participants.get(pair.phone?._serialized)
                            ?? null;
                    }))).filter(Boolean);
                    const actions = globalThis.require('WAWebModifyParticipantsGroupAction');
                    const action = Object.freeze({
                        1: actions.removeParticipants,
                        2: actions.promoteParticipants,
                        3: actions.demoteParticipants,
                    })[args[1]];
                    if (typeof action !== 'function') throw new Error('Unsupported group participant action.');
                    await action(chat, participants);
                    return { status: 200 };
                }
                if (method === 'setGroupSubject') {
                    try {
                        const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                        await globalThis.require('WAWebGroupModifyInfoJob').setGroupSubject(wid, args[1]);
                        return true;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return false;
                        throw error;
                    }
                }
                if (method === 'setGroupDescription') {
                    try {
                        const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                        const chat = await getChatModel(args[0]);
                        const id = await globalThis.require('WAWebMsgKey').newId();
                        await globalThis.require('WAWebGroupModifyInfoJob')
                            .setGroupDescription(wid, args[1], id, chat.groupMetadata.descId);
                        return true;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return false;
                        throw error;
                    }
                }
                if (method === 'setGroupSetting') {
                    const chat = await getChatModel(args[0]);
                    const properties = Object.freeze({
                        1: ['member_add_mode', args[2] ? 0 : 1],
                        2: ['announcement', args[2] ? 1 : 0],
                        3: ['restrict', args[2] ? 1 : 0],
                    });
                    const property = properties[args[1]];
                    if (!property) throw new Error('Unsupported group setting.');
                    try {
                        await globalThis.require('WAWebSetPropertyGroupAction').setGroupProperty(chat, ...property);
                        return true;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return false;
                        throw error;
                    }
                }
                if (method === 'deleteGroupPicture') return await globalThis.WWebJS.deletePicture(args[0]);
                if (method === 'setGroupPicture') return await globalThis.WWebJS.setPicture(args[0], args[1]);
                if (method === 'getGroupInviteCode') {
                    try {
                        const result = await globalThis.require('WAWebMexFetchGroupInviteCodeJob').fetchMexGroupInviteCode(args[0]);
                        return result?.code ?? result ?? null;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return null;
                        throw error;
                    }
                }
                if (method === 'revokeGroupInvite') {
                    const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    const result = await globalThis.require('WAWebGroupQueryJob').resetGroupInviteCode(wid);
                    return result.code;
                }
                if (method === 'leaveGroup') {
                    await globalThis.require('WAWebExitGroupAction').sendExitGroup(await getChatModel(args[0]));
                    return null;
                }
                if (method === 'addGroupParticipants') {
                    const [groupId, participantIds, options] = args;
                    const groupWid = globalThis.require('WAWebWidFactory').createWid(groupId);
                    const group = Chat.get(groupWid) ?? await Chat.find(groupWid);
                    await globalThis.require('WAWebGroupQueryJob').queryAndUpdateGroupMetadataById({ id: groupId });
                    const current = group.groupMetadata?.participants.serialize();
                    if (!current) return "AddParticipantsError: The participant can't be added to an empty group";
                    if (!group.iAmAdmin()) return 'AddParticipantsError: You have no admin rights to add a participant to a group';
                    const messages = Object.freeze({
                        200: 'The participant was added successfully',
                        403: 'The participant can be added by sending private invitation only',
                        404: 'The phone number is not registered on WhatsApp',
                        408: 'You cannot add this participant because they recently left the group',
                        409: 'The participant is already a group member',
                        417: "The participant can't be added to the community",
                        419: "The participant can't be added because the group is full",
                    });
                    const results = {};
                    const sleepValue = options.sleep ?? [250, 500];
                    const sleepTime = () => {
                        if (Number.isInteger(sleepValue)) return sleepValue;
                        if (!Array.isArray(sleepValue) || sleepValue.length === 0) return 0;
                        if (sleepValue.length === 1) return sleepValue[0];
                        let [minimum, maximum] = sleepValue;
                        if (maximum - minimum < 100) minimum = maximum, maximum += 100;
                        return Math.floor(Math.random() * (maximum - minimum + 1)) + minimum;
                    };
                    for (let index = 0; index < participantIds.length; index += 1) {
                        let participant = globalThis.require('WAWebWidFactory').createWid(participantIds[index]);
                        const participantId = serialized(participant);
                        results[participantId] = { code: 0, message: '', isInviteV4Sent: false };
                        if (current.some((item) => serialized(item.id ?? item) === participantId)) {
                            results[participantId] = { code: 409, message: messages[409], isInviteV4Sent: false };
                            continue;
                        }
                        if (participant.server === 'lid') participant = globalThis.require('WAWebApiContact').getPhoneNumber(participant);
                        if (!(await globalThis.require('WAWebQueryExistsJob').queryWidExists(participant))?.wid) {
                            results[participantId] = { code: 404, message: messages[404], isInviteV4Sent: false };
                            continue;
                        }
                        const rpc = await globalThis.WWebJS.getAddParticipantsRpcResult(groupWid, participant);
                        const code = rpc.code;
                        results[participantId] = {
                            code,
                            message: messages[code] ?? 'An unknown error occurred while adding a participant',
                            isInviteV4Sent: false,
                        };
                        if (options.autoSendInviteV4 !== false && code === 403 && rpc.name === 'ParticipantRequestCodeCanBeSent') {
                            globalThis.require('WAWebCollections').Contact.gadd(participant, { silent: true });
                            const userChat = Chat.get(participant) ?? await Chat.find(participant);
                            if (userChat) {
                                const sent = await globalThis.require('WAWebChatSendMessages').sendGroupInviteMessage(
                                    userChat,
                                    groupId,
                                    group.formattedTitle || group.name,
                                    rpc.inviteV4Code,
                                    rpc.inviteV4CodeExp,
                                    options.comment ?? '',
                                    await globalThis.WWebJS.getProfilePicThumbToBase64(groupWid),
                                );
                                results[participantId].isInviteV4Sent = sent.messageSendResult === 'OK';
                            }
                        }
                        if (index < participantIds.length - 1) await sleep(sleepTime());
                    }
                    return results;
                }
                if (method === 'getGroupMembershipRequests') {
                    const wid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    const requests = await globalThis.require('WAWebApiMembershipApprovalRequestStore')
                        .getMembershipApprovalRequests(wid);
                    const methods = Object.freeze({ NonAdminAdd: 1, InviteLink: 2, LinkedGroupJoin: 3 });
                    return requests.map((request) => ({
                        id: {
                            server: request.id.server,
                            user: request.id.user,
                            _serialized: serialized(request.id),
                        },
                        addedBy: {
                            server: request.addedBy.server,
                            user: request.addedBy.user,
                            _serialized: serialized(request.addedBy),
                        },
                        parentGroupId: request.parentGroupId ? {
                            server: request.parentGroupId.server,
                            user: request.parentGroupId.user,
                            _serialized: serialized(request.parentGroupId),
                        } : null,
                        requestMethod: methods[request.requestMethod] ?? 4,
                        timestamp: request.t,
                    }));
                }
                if (method === 'membershipRequestAction') {
                    const actions = Object.freeze({ 1: 'Approve', 2: 'Reject' });
                    const action = actions[args[1]];
                    if (!action) throw new Error('Unsupported membership request action.');
                    const options = args[2] ?? {};
                    return await globalThis.WWebJS.membershipRequestAction(
                        args[0], action, options.requesterIds ?? null, options.sleep ?? [250, 500],
                    );
                }
                if (method === 'getQuotedMessage') {
                    const message = await getMessageModel(args[0]);
                    if (!message) return null;
                    const quoted = globalThis.require('WAWebQuotedMsgModelUtils').getQuotedMsgObj(message);
                    return quoted ? messageData(quoted) : null;
                }
                if (method === 'forwardMessage') {
                    await globalThis.WWebJS.forwardMessage(args[1], args[0]);
                    return null;
                }
                if (method === 'downloadMessageMedia') {
                    const message = await getMessageModel(args[0]);
                    if (!message?.mediaData || message.mediaData.mediaStage === 'REUPLOADING') return null;
                    await message.downloadMedia({
                        downloadEvenIfExpensive: true,
                        rmrReason: 1,
                        isUserInitiated: true,
                    });
                    if (message.mediaData.mediaStage.includes('ERROR') || message.mediaData.mediaStage === 'FETCHING') return null;
                    try {
                        const cached = globalThis.require('WAWebMediaInMemoryBlobCache')
                            .InMemoryMediaBlobCache.get(message.mediaObject?.filehash);
                        const blob = cached ?? message.mediaObject?.mediaBlob?.forceToBlob?.();
                        if (blob) {
                            return {
                                data: await globalThis.WWebJS.arrayBufferToBase64Async(await blob.arrayBuffer()),
                                mimetype: message.mimetype,
                                filename: message.filename ?? null,
                                filesize: message.size ?? null,
                            };
                        }
                        const qpl = { addAnnotations() { return this; }, addPoint() { return this; } };
                        const data = await globalThis.require('WAWebDownloadManager').downloadManager.downloadAndMaybeDecrypt({
                            directPath: message.directPath,
                            encFilehash: message.encFilehash,
                            filehash: message.filehash,
                            mediaKey: message.mediaKey,
                            mediaKeyTimestamp: message.mediaKeyTimestamp,
                            type: message.type,
                            signal: new AbortController().signal,
                            downloadQpl: qpl,
                        });
                        return {
                            data: await globalThis.WWebJS.arrayBufferToBase64Async(data),
                            mimetype: message.mimetype,
                            filename: message.filename ?? null,
                            filesize: message.size ?? null,
                        };
                    } catch (error) {
                        if (error?.status === 404) return null;
                        throw error;
                    }
                }
                if (method === 'openMessageMediaStream') {
                    const resolved = await globalThis.WWebJS.resolveMediaBlob(args[0]);
                    if (!resolved) return null;
                    const token = globalThis.crypto?.randomUUID?.()
                        ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`;
                    mediaStreams.set(token, resolved.blob);
                    return {
                        token,
                        blobSize: resolved.blob.size,
                        mimetype: resolved.mimetype,
                        filename: resolved.filename ?? null,
                        filesize: resolved.filesize ?? null,
                    };
                }
                if (method === 'readMessageMediaStream') {
                    const blob = mediaStreams.get(args[0]);
                    if (!blob) throw new Error('Media stream is unavailable or closed.');
                    const offset = Number(args[1]);
                    const chunkSize = Number(args[2]);
                    if (!Number.isSafeInteger(offset) || offset < 0
                        || !Number.isSafeInteger(chunkSize) || chunkSize < 1) {
                        throw new Error('Media stream range is invalid.');
                    }
                    const end = Math.min(blob.size, offset + chunkSize);
                    const data = await globalThis.WWebJS.arrayBufferToBase64Async(
                        await blob.slice(offset, end).arrayBuffer(),
                    );
                    const done = end >= blob.size;
                    if (done) mediaStreams.delete(args[0]);
                    return { data, done };
                }
                if (method === 'closeMessageMediaStream') {
                    return mediaStreams.delete(args[0]);
                }
                if (method === 'deleteMessage') {
                    const message = await getMessageModel(args[0]);
                    if (!message) return null;
                    const chat = Chat.get(message.id.remote) ?? await Chat.find(message.id.remote);
                    const capability = globalThis.require('WAWebMsgActionCapability');
                    const canRevoke = capability.canSenderRevokeMsg(message) || capability.canAdminRevokeMsg(message);
                    const command = globalThis.require('WAWebCmd').Cmd;
                    if (args[1] && canRevoke) {
                        await command.sendRevokeMsgs(chat, { list: [message], type: 'message' }, { clearMedia: args[2] });
                    } else {
                        await command.sendDeleteMsgs(chat, { list: [message], type: 'message' }, args[2]);
                    }
                    return null;
                }
                if (method === 'starMessage') {
                    const message = await getMessageModel(args[0]);
                    if (!message || !globalThis.require('WAWebMsgActionCapability').canStarMsg(message)) return null;
                    const chat = await Chat.find(message.id.remote);
                    const command = globalThis.require('WAWebCmd').Cmd;
                    await (args[1] ? command.sendStarMsgs(chat, [message], false) : command.sendUnstarMsgs(chat, [message], false));
                    return null;
                }
                if (method === 'pinMessage') {
                    return await globalThis.WWebJS.pinUnpinMsgAction(args[0], args[1] ? 1 : 2, args[2]);
                }
                if (method === 'reactMessage') {
                    const message = await getMessageModel(args[0]);
                    if (message) {
                        await globalThis.require('WAWebSendReactionMsgAction').sendReactionToMsg(message, args[1]);
                        await sleep(100);
                        const collection = await globalThis.require('WAWebCollections').Reactions.find(args[0]);
                        const reactions = collection?.reactions?.serialize?.() ?? [];
                        const sender = reactions.flatMap((reaction) => reaction.senders ?? [])
                            .findLast((item) => item.reactionText === args[1]);
                        if (sender) emitReaction(sender);
                    }
                    return null;
                }
                if (method === 'editMessage') {
                    const message = await getMessageModel(args[0]);
                    if (!message) return null;
                    const capability = globalThis.require('WAWebMsgActionCapability');
                    if (!capability.canEditText(message) && !capability.canEditCaption(message)) return null;
                    const edited = await globalThis.WWebJS.editMessage(message, args[1], args[2]);
                    return messageData(edited ?? message);
                }
                if (method === 'voteMessage') {
                    const message = await getMessageModel(args[0]);
                    if (!message) return null;
                    const ids = new Set();
                    for (const option of message.pollOptions ?? []) {
                        if (args[1].includes(option.name)) ids.add(option.localId);
                    }
                    await globalThis.require('WAWebPollsSendVoteMsgAction').sendVote(message, ids);
                    return null;
                }
                if (method === 'getMessageInfo') {
                    const message = await getMessageModel(args[0]);
                    if (!message?.id?.fromMe) return null;
                    const info = await globalThis.require('WAWebApiMessageInfoStore').queryMsgInfo(message.id);
                    const receipts = (items) => (items ?? []).map((receipt) => ({
                        id: {
                            server: receipt.id.server,
                            user: receipt.id.user,
                            _serialized: serialized(receipt.id),
                        },
                        timestamp: receipt.t,
                    }));
                    return {
                        delivery: receipts(info.delivery),
                        deliveryRemaining: info.deliveryRemaining ?? 0,
                        played: receipts(info.played),
                        playedRemaining: info.playedRemaining ?? 0,
                        read: receipts(info.read),
                        readRemaining: info.readRemaining ?? 0,
                    };
                }
                if (method === 'reloadMessage') {
                    const message = await getMessageModel(args[0]);
                    return message ? messageData(message) : null;
                }
                if (method === 'getMessageOrder') {
                    return await globalThis.WWebJS.getOrderDetail(args[0], args[1], args[2]);
                }
                if (method === 'getMessagePayment') {
                    const message = await getMessageModel(args[0]);
                    if (!message) return null;
                    const value = message.serialize();
                    const paymentStatuses = Object.freeze({
                        0: 1, 1: 2, 2: 3, 3: 4, 4: 5, 5: 6,
                        6: 7, 7: 8, 8: 9, 9: 10, 10: 11, 11: 12,
                    });
                    return {
                        id: serialized(value.id),
                        paymentCurrency: value.paymentCurrency ?? '',
                        paymentAmount1000: Number.isInteger(value.paymentAmount1000) ? value.paymentAmount1000 : 0,
                        paymentMessageReceiverJid: serialized(value.paymentMessageReceiverJid),
                        paymentTransactionTimestamp: Number.isInteger(value.paymentTransactionTimestamp)
                            ? value.paymentTransactionTimestamp : 0,
                        paymentStatus: paymentStatuses[value.paymentStatus] ?? 1,
                        paymentTxnStatus: paymentStatuses[value.paymentTxnStatus] ?? 1,
                        paymentNote: value.paymentNoteMsg?.body ?? null,
                    };
                }
                if (method === 'getMessageReactions') {
                    const collection = await globalThis.require('WAWebCollections').Reactions.find(args[0]);
                    if (!collection?.reactions?.length) return [];
                    const ackValues = Object.freeze({ '-1': 1, '0': 2, '1': 3, '2': 4, '3': 5, '4': 6 });
                    return collection.reactions.serialize().map((item) => ({
                        id: item.id ?? item.reactionText ?? '',
                        aggregateEmoji: item.aggregateEmoji ?? item.reactionText ?? '',
                        hasReactionByMe: Boolean(item.hasReactionByMe),
                        senders: (item.senders ?? []).map((sender) => ({
                            id: messageIdData(sender.msgKey),
                            orphan: Number.isInteger(sender.orphan) ? sender.orphan : 0,
                            orphanReason: sender.orphanReason ?? null,
                            timestamp: Math.round((sender.timestamp ?? 0) / 1000),
                            reaction: sender.reactionText ?? '',
                            read: Boolean(sender.read),
                            msgId: messageIdData(sender.parentMsgKey),
                            senderId: serialized(sender.senderUserJid),
                            ack: Number.isInteger(sender.ack) ? (ackValues[String(sender.ack)] ?? null) : null,
                        })),
                    }));
                }
                if (method === 'getPollVotes') {
                    const message = await getMessageModel(args[0]);
                    if (!message) return [];
                    if (message.type !== 'poll_creation') throw new Error('Poll votes require a poll creation message.');
                    const rows = await globalThis.require('WAWebPollsVotesSchema').getTable()
                        .equals(['parentMsgKey'], message.id.toString());
                    return rows.map((row) => {
                        const ids = Array.from(new Uint8Array(row.selectedOptionLocalIds ?? []));
                        return {
                            voter: serialized(row.sender),
                            selectedOptions: ids.map((id) => ({
                                id,
                                name: message.pollOptions?.find((option) => option.localId === id)?.name ?? '',
                            })),
                            interractedAtTs: Number.isInteger(row.senderTimestampMs) ? row.senderTimestampMs : 0,
                            parentMessage: messageData(message),
                        };
                    });
                }
                if (method === 'getProductMetadata') {
                    return await globalThis.WWebJS.getProductMetadata(args[0]);
                }
                if (method === 'acceptGroupV4Invite') {
                    const invite = args[0];
                    if (!invite?.inviteCode) throw new Error('Invalid invite code.');
                    if (invite.inviteCodeExp === 0) throw new Error('Expired invite code.');
                    const user = globalThis.require('WAWebWidFactory').createWid(invite.fromId);
                    return await globalThis.require('WAWebGroupInviteV4Job').joinGroupViaInviteV4(
                        invite.inviteCode, String(invite.inviteCodeExp), invite.groupId, user,
                    );
                }
                if (method === 'editScheduledEvent') {
                    const message = await getMessageModel(args[0]);
                    if (!message) return null;
                    const event = args[1];
                    const callTypes = Object.freeze({ 1: 'none', 2: 'voice', 3: 'video' });
                    await globalThis.require('WAWebSendEventEditMsgAction').sendEventEditMessage({
                        name: event.name,
                        description: event.description,
                        startTime: event.startTime,
                        endTime: event.endTime,
                        location: event.location,
                        callType: callTypes[event.callType] ?? 'none',
                        isEventCanceled: event.isEventCanceled,
                    }, message);
                    const edited = await getMessageModel(args[0]);
                    return edited ? messageData(edited) : null;
                }
                if (method === 'getChannelSubscribers') {
                    const channel = await getChatModel(args[0]);
                    if (!channel) return [];
                    const limit = Number.isInteger(args[1])
                        ? args[1]
                        : globalThis.require('WAWebNewsletterGatingUtils').getMaxSubscriberNumber();
                    const response = await globalThis.require('WAWebMexFetchNewsletterSubscribersJob')
                        .mexFetchNewsletterSubscribers(args[0], limit);
                    const subscribers = globalThis.require('WAWebNewsletterSubscriberListAction')
                        .getSubscribersInContacts(response.subscribers);
                    const roles = Object.freeze({ OWNER: 1, ADMIN: 2, SUBSCRIBER: 3 });
                    return subscribers.map((subscriber) => ({
                        contact: contactData(subscriber.contact),
                        role: roles[String(subscriber.role).toUpperCase()] ?? 4,
                    }));
                }
                if (method === 'getChannelByInviteCode') {
                    try {
                        const metadata = await globalThis.WWebJS.getChannelMetadata(args[0]);
                        return await globalThis.WWebJS.getChat(serialized(metadata.id));
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return null;
                        throw error;
                    }
                }
                if (method === 'createGroup') {
                    const title = args[0];
                    const participants = Array.isArray(args[1]) ? args[1] : [];
                    const options = args[2] ?? {};
                    const participantData = {};
                    const participantWids = [];
                    const failedParticipants = [];
                    const messages = Object.freeze({
                        200: 'The participant was added successfully',
                        403: 'The participant can be added by sending private invitation only',
                        404: 'The phone number is not registered on WhatsApp',
                    });
                    for (const participant of participants) {
                        const wid = globalThis.require('WAWebWidFactory').createWid(participant);
                        if ((await globalThis.require('WAWebQueryExistsJob').queryWidExists(wid))?.wid) {
                            participantWids.push({ phoneNumber: wid });
                        } else {
                            failedParticipants.push(participant);
                        }
                    }
                    const parentGroupId = options.parentGroupId
                        ? globalThis.require('WAWebWidFactory').createWid(options.parentGroupId)
                        : undefined;
                    let result;
                    try {
                        result = await globalThis.require('WAWebGroupCreateJob').createGroup({
                            addressingModeOverride: 'lid',
                            memberAddMode: options.memberAddMode === true,
                            membershipApprovalMode: options.membershipApprovalMode === true,
                            announce: options.isAnnounce === true,
                            restrict: options.isRestrict === undefined ? false : !options.isRestrict,
                            ephemeralDuration: Number.isInteger(options.messageTimer) ? options.messageTimer : 0,
                            parentGroupId,
                            title,
                        }, participantWids);
                    } catch (_error) {
                        return 'CreateGroupError: An unknown error occupied while creating a group';
                    }
                    for (const participantResult of result.participants) {
                        if (participantResult.wid.server === 'lid') {
                            participantResult.wid = globalThis.require('WAWebApiContact')
                                .getPhoneNumber(participantResult.wid);
                        }
                        const participantId = serialized(participantResult.wid);
                        const statusCode = Number.isInteger(participantResult.error) ? participantResult.error : 200;
                        let isInviteV4Sent = false;
                        if (options.autoSendInviteV4 !== false && statusCode === 403) {
                            globalThis.require('WAWebCollections').Contact.gadd(participantResult.wid, { silent: true });
                            const chat = globalThis.require('WAWebCollections').Chat.get(participantResult.wid)
                                ?? await globalThis.require('WAWebCollections').Chat.find(participantResult.wid);
                            const inviteResult = await globalThis.require('WAWebChatSendMessages').sendGroupInviteMessage(
                                chat,
                                serialized(result.wid),
                                result.subject,
                                participantResult.invite_code,
                                participantResult.invite_code_exp,
                                String(options.comment ?? ''),
                                await globalThis.WWebJS.getProfilePicThumbToBase64(result.wid),
                            );
                            isInviteV4Sent = inviteResult?.messageSendResult === 'OK';
                        }
                        participantData[participantId] = {
                            statusCode,
                            message: messages[statusCode] ?? 'An unknown error occupied while adding a participant',
                            isGroupCreator: participantResult.type === 'superadmin',
                            isInviteV4Sent,
                        };
                    }
                    for (const participant of failedParticipants) {
                        participantData[participant] = {
                            statusCode: 404,
                            message: messages[404],
                            isGroupCreator: false,
                            isInviteV4Sent: false,
                        };
                    }
                    return {
                        title,
                        gid: {
                            server: result.wid.server,
                            user: result.wid.user,
                            _serialized: serialized(result.wid),
                        },
                        participants: participantData,
                    };
                }
                if (method === 'createChannel') {
                    const title = args[0];
                    const options = args[1] ?? {};
                    if (!globalThis.require('WAWebNewsletterGatingUtils').isNewsletterCreationEnabled()) {
                        return 'CreateChannelError: A channel creation is not enabled';
                    }
                    let picture = options.picture ?? null;
                    if (picture) {
                        picture = await globalThis.WWebJS.cropAndResizeImage(picture, {
                            asDataUrl: true, mimetype: 'image/jpeg', size: 640, quality: 1,
                        });
                    }
                    try {
                        const response = await globalThis.require('WAWebNewsletterCreateQueryJob')
                            .createNewsletterQuery({
                                name: title,
                                description: options.description ?? null,
                                picture,
                            });
                        const nid = globalThis.require('WAWebJidToWid').newsletterJidToWid(response.idJid);
                        return {
                            title,
                            nid: { server: nid.server, user: nid.user, _serialized: serialized(nid) },
                            inviteLink: 'https://whatsapp.com/channel/'
                                + response.newsletterInviteLinkMetadataMixin.inviteCode,
                            createdAtTs: response.newsletterCreationTimeMetadataMixin.creationTimeValue,
                        };
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') {
                            return 'CreateChannelError: An error occupied while creating a channel';
                        }
                        throw error;
                    }
                }
                if (method === 'subscribeToChannel' || method === 'unsubscribeFromChannel') {
                    return await globalThis.WWebJS.subscribeToUnsubscribeFromChannel(
                        args[0],
                        method === 'subscribeToChannel' ? 'Subscribe' : 'Unsubscribe',
                        method === 'unsubscribeFromChannel' ? (args[1] ?? {}) : undefined,
                    );
                }
                if (method === 'searchChannels') {
                    const options = args[0] ?? {};
                    const currentRegion = globalThis.require('WAWebL10N').getRegion();
                    let countryCodes = Array.isArray(options.countryCodes) && options.countryCodes.length > 0
                        ? options.countryCodes
                        : [currentRegion];
                    const countryNames = globalThis.require('WAWebCountriesNativeCountryNames');
                    const validCodes = Object.keys(countryNames.countryCodesIso ?? countryNames);
                    if (!(countryCodes.length === 1 && countryCodes[0] === currentRegion)) {
                        countryCodes = countryCodes.filter((code) => validCodes.includes(code));
                    }
                    const views = Object.freeze({ 1: 'RECOMMENDED', 2: 'TRENDING', 3: 'POPULAR', 4: 'NEW' });
                    const gating = globalThis.require('WAWebNewsletterGatingUtils');
                    const originalPageSize = gating.getNewsletterDirectoryPageSize;
                    const limit = Number.isInteger(options.limit) && options.limit > 0 ? options.limit : 50;
                    if (limit !== 50) gating.getNewsletterDirectoryPageSize = () => limit;
                    try {
                        const response = await globalThis.require('WAWebNewsletterDirectorySearchAction')
                            .fetchNewsletterDirectories({
                                searchText: String(options.searchText ?? '').trim(),
                                countryCodes,
                                skipSubscribedNewsletters: options.skipSubscribedNewsletters === true,
                                view: views[options.view] ?? 'RECOMMENDED',
                                categories: [],
                                cursorToken: '',
                            });
                        return response.newsletters
                            ? await Promise.all(response.newsletters.map((channel) =>
                                globalThis.WWebJS.getChatModel(channel, { isChannel: true })))
                            : [];
                    } finally {
                        if (limit !== 50) gating.getNewsletterDirectoryPageSize = originalPageSize;
                    }
                }
                if (method === 'setChannelMetadata' || method === 'setChannelReactionSetting') {
                    const channel = await getChatModel(args[0]);
                    if (!channel) return false;
                    let value = args[1];
                    let property = args[2];
                    if (method === 'setChannelReactionSetting') {
                        const reactionSettings = Object.freeze({ 1: 3, 2: 1, 3: 0 });
                        if (reactionSettings[args[1]] === undefined) return false;
                        value = { reactionCodesSetting: reactionSettings[args[1]] };
                        property = { editReactionCodesSetting: true };
                    }
                    if (property?.editPicture) {
                        value.picture = value.picture
                            ? await globalThis.WWebJS.cropAndResizeImage(value.picture, {
                                asDataUrl: true, mimetype: 'image/jpeg', size: 640, quality: 1,
                            })
                            : null;
                    }
                    try {
                        await globalThis.require('WAWebEditNewsletterMetadataAction')
                            .editNewsletterMetadataAction(channel, property, value);
                        return true;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return false;
                        throw error;
                    }
                }
                if (method === 'muteChannel') {
                    try {
                        await globalThis.require('WAWebNewsletterUpdateUserSettingJob').updateNewsletterUserSetting({
                            newsletterJid: globalThis.require('WAJids').toNewsletterJid(args[0]),
                            type: globalThis.require('WAWebNewsletterModelUtils').ADMIN_NOTIFICATIONS,
                            muteExpirationValue: args[1]
                                ? globalThis.require('WAWebNewsletterModelUtils').MUTED_STATE
                                : globalThis.require('WAWebNewsletterModelUtils').UNMUTED_STATE,
                        });
                        return true;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return false;
                        throw error;
                    }
                }
                if (method === 'sendChannelAdminInvite') {
                    const channelWid = globalThis.require('WAWebWidFactory').createWid(args[1]);
                    const chatWid = globalThis.require('WAWebWidFactory').createWid(args[0]);
                    if (!chatWid.isUser()) return false;
                    const chat = globalThis.require('WAWebCollections').Chat.get(chatWid)
                        ?? await globalThis.require('WAWebCollections').Chat.find(chatWid);
                    const response = await globalThis.require('WAWebNewsletterSendMsgAction')
                        .sendNewsletterAdminInviteMessage(chat, {
                            newsletterWid: channelWid,
                            invitee: chatWid,
                            inviteMessage: args[2]?.comment,
                            base64Thumb: await globalThis.WWebJS.getProfilePicThumbToBase64(channelWid),
                        });
                    return response?.messageSendResult === 'OK';
                }
                if (method === 'acceptChannelAdminInvite') {
                    try {
                        await globalThis.require('WAWebMexAcceptNewsletterAdminInviteJob')
                            .acceptNewsletterAdminInvite(args[0]);
                        return true;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return false;
                        throw error;
                    }
                }
                if (method === 'revokeChannelAdminInvite' || method === 'demoteChannelAdmin') {
                    try {
                        const userWid = globalThis.require('WAWebWidFactory').createWid(args[1]);
                        if (method === 'revokeChannelAdminInvite') {
                            await globalThis.require('WAWebMexRevokeNewsletterAdminInviteJob')
                                .revokeNewsletterAdminInvite(args[0], userWid);
                        } else {
                            await globalThis.require('WAWebDemoteNewsletterAdminAction')
                                .demoteNewsletterAdmin(args[0], userWid);
                        }
                        return true;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return false;
                        throw error;
                    }
                }
                if (method === 'transferChannelOwnership') {
                    const channel = await getChatModel(args[0]);
                    const newOwner = globalThis.require('WAWebCollections').Contact.get(args[1])
                        ?? await globalThis.require('WAWebCollections').Contact.find(args[1]);
                    if (!channel || !newOwner) return false;
                    if (!channel.newsletterMetadata) {
                        await globalThis.require('WAWebCollections').NewsletterMetadataCollection.update(channel.id);
                    }
                    try {
                        await globalThis.require('WAWebChangeNewsletterOwnerAction')
                            .changeNewsletterOwnerAction(channel, newOwner);
                        if (args[2]?.shouldDismissSelfAsAdmin) {
                            const me = globalThis.require('WAWebContactCollection').getMeContact();
                            if (me) {
                                await globalThis.require('WAWebNewsletterDemoteAdminJob')
                                    .demoteNewsletterAdminAction(channel, me);
                            }
                        }
                        return true;
                    } catch (_error) {
                        return false;
                    }
                }
                if (method === 'fetchChannelMessages') {
                    const channel = await getChatModel(args[0]);
                    if (!channel) return [];
                    const options = args[1] ?? {};
                    const filter = (message) => !message.isNotification
                        && message.type !== 'newsletter_notification'
                        && (options.fromMe === undefined || message.id.fromMe === options.fromMe);
                    let messages = channel.msgs.getModelsArray().filter(filter);
                    if (Number.isInteger(options.limit) && options.limit > 0) {
                        while (messages.length < options.limit) {
                            const loaded = await globalThis.require('WAWebChatLoadMessages').loadEarlierMsgs({ chat: channel });
                            if (!loaded?.length) break;
                            messages = [...loaded.filter(filter), ...messages];
                        }
                        messages.sort((left, right) => left.t - right.t);
                        messages = messages.slice(-options.limit);
                    }
                    return messages.map(messageData);
                }
                if (method === 'deleteChannel') {
                    const channel = await getChatModel(args[0]);
                    if (!channel) return false;
                    try {
                        await globalThis.require('WAWebNewsletterDeleteAction').deleteNewsletterAction(channel);
                        return true;
                    } catch (error) {
                        if (error?.name === 'ServerStatusCodeError') return false;
                        throw error;
                    }
                }
                const aliases = Object.freeze({
                });
                const target = globalThis.WWebJS[aliases[method] ?? method];
                if (typeof target !== 'function') {
                    throw new Error('WhatsApp Web utility is unavailable: ' + method);
                }
                return await target(...args);
            },
            sendContent: async (chatId, content, options = {}) => {
                const chat = await globalThis.WWebJS.getChat(chatId, { getAsModel: false });
                if (!chat) throw new Error('Chat was not found.');
                let body = '';
                const internal = { ...options };
                if (content.kind === 1) {
                    body = content.text;
                } else if (content.kind === 2) {
                    internal.media = content.media;
                } else if (content.kind === 3) {
                    internal.location = {
                        latitude: content.latitude,
                        longitude: content.longitude,
                        description: content.description,
                        url: content.url,
                    };
                } else if (content.kind === 4) {
                    internal.poll = {
                        pollName: content.pollName,
                        pollOptions: content.pollOptions.map((name, localId) => ({ name, localId })),
                        options: {
                            allowMultipleAnswers: content.allowMultipleAnswers,
                            messageSecret: content.messageSecret,
                        },
                    };
                } else if (content.kind === 5) {
                    internal.contactCard = content.contactId;
                } else if (content.kind === 6) {
                    internal.contactCardList = content.contactIds;
                } else if (content.kind === 7) {
                    internal.list = {
                        body: content.body,
                        buttonText: content.buttonText,
                        sections: content.sections,
                        title: content.title,
                        footer: content.footer,
                    };
                } else if (content.kind === 8) {
                    if (content.media) internal.media = content.media;
                    internal.buttons = {
                        body: content.body,
                        buttons: content.buttons.map((button, index) => ({
                            buttonId: button.id ?? String(index),
                            buttonText: { displayText: button.body },
                            type: 1,
                        })),
                        title: content.title,
                        footer: content.footer,
                    };
                } else if (content.kind === 9) {
                    const callTypes = Object.freeze({ 1: 'none', 2: 'voice', 3: 'video' });
                    internal.event = {
                        name: content.name,
                        startTimeTs: content.startTime,
                        eventSendOptions: {
                            description: content.description,
                            endTimeTs: content.endTime,
                            location: content.location,
                            callType: callTypes[content.callType] ?? 'none',
                            isEventCanceled: content.isEventCanceled,
                            messageSecret: content.messageSecret,
                        },
                    };
                } else {
                    throw new Error('Unsupported message content kind.');
                }
                if (internal.sendSeen !== false) {
                    await globalThis.WWebJS.sendSeen(chatId);
                }
                delete internal.sendSeen;
                if (internal.sendMediaAsSticker && internal.media
                    && typeof internal.media.mimetype === 'string'
                    && internal.media.mimetype.startsWith('image/')
                ) {
                    internal.media = await globalThis.WWebJS.toStickerData(internal.media);
                    internal.media.data = stickerMetadata(
                        internal.media.data,
                        internal.stickerName,
                        internal.stickerAuthor,
                        internal.stickerCategories,
                    );
                }
                const messages = globalThis.require('WAWebCollections').Msg;
                const knownIds = new Set(messages.getModelsArray().map((message) => serialized(message.id)));
                let sent = await globalThis.WWebJS.sendMessage(chat, body, internal);
                if (!sent) {
                    const chatIdValue = serialized(chat.id);
                    const candidates = messages.getModelsArray().filter((message) =>
                        !knownIds.has(serialized(message.id))
                        && message.id?.fromMe === true
                        && serialized(message.id?.remote) === chatIdValue
                        && (content.kind !== 1 || message.body === body));
                    sent = candidates.at(-1);
                }
                if (!sent) throw new Error('Sent message was not found in the WhatsApp message collection.');
                if (content.kind === 2) emitMediaUploaded(sent);
                return messageData(sent);
            },
        });

        if (Socket.hasSynced === true) {
            ready();
        } else if (Socket.state === 'UNPAIRED' || Socket.state === 'UNPAIRED_IDLE') {
            await emitQr(Conn.ref);
        }
        globalThis.__pamWhatsAppBridgeState = 2;
    };

    void install().catch((error) => {
        globalThis.__pamWhatsAppBridgeState = 3;
        emit(EVENT.ERROR, { message: error instanceof Error ? error.message : String(error) });
    });
})();
JAVASCRIPT;
    }
}
