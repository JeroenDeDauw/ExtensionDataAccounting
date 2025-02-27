<?php
/**
 * Hooks for Example extension.
 *
 * @file
 */

namespace MediaWiki\Extension\Example;

# include / exclude for debugging
error_reporting(E_ALL);
ini_set("display_errors", 1);

use MediaWiki\Revision\SlotRecord;
use DatabaseUpdater;
use MediaWiki\MediaWikiServices;

require_once('Util.php');
require_once('ApiUtil.php');

function calculateMetadataHash($domainId, $timestamp, $previousVerificationHash = "") {
    return getHashSum($domainId . $timestamp . $previousVerificationHash);
}

function calculateSignatureHash($signature, $publicKey) {
    return getHashSum($signature . $publicKey);
}

function calculateWitnessHash($page_manifest_verification_hash, $merkle_root, $witness_network, $witness_tx_hash ) {
    return getHashSum($page_manifest_verification_hash . $merkle_root . $witness_network . $witness_tx_hash);
}

function calculateVerificationHash($contentHash, $metadataHash, $signature_hash, $witness_hash) {
    return getHashSum($contentHash . $metadataHash . $signature_hash . $witness_hash);
}

function makeEmptyIfNonce($x) {
    return ($x == "nonce") ? "": $x;
}

function getPageVerificationData($dbr, $previous_rev_id) {
    $row = $dbr->selectRow(
        'page_verification',
        [ 'rev_id', 'hash_verification', 'signature', 'public_key', 'wallet_address','witness_event_id' ],
        "rev_id = $previous_rev_id",
        __METHOD__
    );
    if (!$row) {
        // When $row is empty, we have to construct $output consisting of empty
        // strings.
        return [
            'hash_verification' => "",
            'signature' => "",
            'public_key' => "",
            'wallet_address' => "",
            'witness_event_id' => null,
        ];
    }
    $output = [
        'hash_verification' => $row->hash_verification, 
        'signature' =>  $row->signature,
        'public_key' => $row->public_key,
        'wallet_address' => $row->wallet_address,
        'witness_event_id' => $row->witness_event_id
    ];
    return $output;
}

class HashWriterHooks implements
    \MediaWiki\Page\Hook\RevisionFromEditCompleteHook,
    \MediaWiki\Revision\Hook\RevisionRecordInsertedHook,
    \MediaWiki\Page\Hook\ArticleDeleteCompleteHook,
    \MediaWiki\Hook\PageMoveCompleteHook,
    \MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook
{

    public function onRevisionRecordInserted( $revisionRecord ) {
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $lb->getConnectionRef( DB_MASTER );
        $table_name = 'page_verification';
        $data = [
            'page_title' => $revisionRecord->getPageAsLinkTarget(),
            'page_id' => $revisionRecord->getPageId(),
            'rev_id' => $revisionRecord->getID(),
            'time_stamp' => $revisionRecord->getTimestamp(),
        ];

        $dbw->insert($table_name, $data, __METHOD__);
    }

    public function onRevisionFromEditComplete( $wikiPage, $rev, $originalRevId, $user, &$tags ) {
        /** 
         * We check if a comment 'revision imported' or 'revisions imported' is
         * present which indicates that we are importing a page, if we import a
         * page we do not run the hook, we would prefer a upgraded version of
         * the revision object which allows us to capture the context to make
         * this hack unnecessary
         */

        $comment = $rev->getComment()->text;
        //if (strpos($comment, 'revision imported') || strpos($comment, 'revisions imported')) {
        //    // If we are here it means we are importing from an XML file.
        //    return;
        //}
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $lb->getConnectionRef( DB_MASTER );

        // CONTENT DATA HASH CALCULATOR
        $pageContent = $rev->getContent(SlotRecord::MAIN)->serialize();
        $contentHash = getHashSum($pageContent);

        // GET DATA FOR META DATA and SIGNATURE DATA
        $parentId = $rev->getParentId();
        $verificationData = getPageVerificationData($dbw, $parentId);

        // META DATA HASH CALCULATOR
        $previousVerificationHash = $verificationData['hash_verification'];
        $domainId = getDomainId();
        $timestamp = $rev->getTimeStamp();
        $metadataHash = calculateMetadataHash($domainId, $timestamp, $previousVerificationHash);

        // SIGNATURE DATA HASH CALCULATOR
        $signature = $verificationData['signature'];
        $publicKey = $verificationData['public_key'];
        $signatureHash = calculateSignatureHash($signature, $publicKey);

        // WITNESS DATA HASH CALCULATOR
        $witnessData = getWitnessData($verificationData['witness_event_id']);
        if (!empty($witnessData)) {
            $page_manifest_verification_hash = $witnessData['witness_event_verification_hash'];
            $merkle_root = $witnessData['merkle_root'];
            $witness_network = $witnessData['witness_network'];
            $witness_tx_hash = $witnessData['witness_event_transaction_hash'];
            $witnessHash = calculateWitnessHash($page_manifest_verification_hash, $merkle_root, $witness_network, $witness_tx_hash );
        } else {
            $witnessHash = '';
        }

        $data = [
            'domain_id' => getDomainId(),
            'page_title' => $wikiPage->getTitle(),
            'page_id' => $wikiPage->getId(),
            'rev_id' => $rev->getID(),
            'hash_content' => $contentHash,
            'time_stamp' => $timestamp,
            'hash_metadata' => $metadataHash,
            'hash_verification' => calculateVerificationHash($contentHash, $metadataHash, $signatureHash, $witnessHash),
            'signature' => '',
            'public_key' => '',
            'wallet_address' => '',
            'source' => 'default',
        ];
        $dbw->update('page_verification', $data, ['rev_id' => $rev->getID()], __METHOD__);
        /** re-initilizing variables to ensure they do not hold values for the next revision. */
        $rev_id =[];
        $pageContent =[];
        $contentHash =[];
        $parentId =[];
        $metadata =[];
        $metadataHash =[];
        $data =[];
    }

    public function onArticleDeleteComplete( $wikiPage, $user, $reason, $id,
		$content, $logEntry, $archivedRevisionCount
    ) {
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $lb->getConnectionRef( DB_MASTER );
        $dbw->delete( 'page_verification', [ 'page_id' => $id ], __METHOD__ );
    }

	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
        $old_title = $old->getText();
        $new_title = $new->getText();
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $lb->getConnectionRef( DB_MASTER );
        $dbw->update(
            'page_verification',
            ['page_title' => $new_title],
            ['page_title' => $old_title,
             'page_id' => $pageid],
            __METHOD__
        );
    }

    /**
     * Register our database schema.
     *
     * @see https://www.mediawiki.org/wiki/Manual:Hooks/LoadExtensionSchemaUpdates
     * @param DatabaseUpdater $updater
     */
    public function onLoadExtensionSchemaUpdates( $updater ) {
        $updater->addExtensionTable( 'data_accounting', dirname( __DIR__ ) . '/sql/data_accounting.sql' );
    }
}
