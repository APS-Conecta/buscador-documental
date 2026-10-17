<?php
// m9 corrected (S1 phase 4, C7). The S0 probe (spikes/s0-harness/bench/m9_isreadable.php, left untouched) listed
// download-disabled hits as plain readable, enforced neither share expiry nor "any readable node", and walked
// ancestors only up to the viewer's mount root. This copy implements the S1 recheck semantics (design section 5):
//   - a hit needs at least one readable node over ALL the user's mounts; a share past its expiry does not count;
//   - download disabled => hit survives, snippet not allowed (reported as snippet_off);
//   - exclusion = `rag-excluded` on the file or any ancestor up to the STORAGE root (oc_filecache parent ids).
// Usage: php m9_isreadable.php <uid> <variant> <hits.json>
//   V0    naive: per hit getById, per hit ancestor walk, one haveTag per ancestor
//   V1    + folder memo (ancestor result per folder id)
//   V2    + bulk tags (parents memoed, ONE getTagIdsForObjects over all ancestor ids)
//   V3    V2 with the readable stage done by Folder::search constrained to the candidate ids (no getById per hit)
//   prod  the shipped OCA\BuscadorDocumental\Search\Recheck (needs the app enabled)
// One fresh PHP process per call, like a request. Prints one JSON line: setup_ms (FS setup + tag id resolution, where
// the variant does it), check_ms, counts, and `decisions` (file id => o ok | s ok, snippet off | d denied | x excluded).
require_once '/var/www/html/lib/base.php';

use OC\Files\Search\SearchComparison;
use OC\Files\Search\SearchQuery;
use OCP\Files\Search\ISearchComparison;

[, $uid, $variant, $hitsFile] = $argv;
$hits = json_decode(file_get_contents($hitsFile), true);
$ids = array_map(static fn (array $h): int => (int)$h['file_id'], $hits);
$c = \OC::$server;
$db = $c->get(\OCP\IDBConnection::class);

$t0 = hrtime(true);
\OC_Util::tearDownFS();
\OC_Util::setupFS($uid);
$userFolder = $c->get(\OCP\Files\IRootFolder::class)->getUserFolder($uid);
$mapper = $c->get(\OCP\SystemTag\ISystemTagObjectMapper::class);
$tagIds = [];
if ($variant !== 'prod') {
	foreach ($c->get(\OCP\SystemTag\ISystemTagManager::class)->getAllTags(null, 'rag-excluded') as $t) {
		if ($t->getName() === 'rag-excluded') {
			$tagIds[] = (string)$t->getId();
		}
	}
}
$setup = (hrtime(true) - $t0) / 1e6;

/** @return bool|null null unusable; true readable with download; false readable, download disabled */
$judge = static function (\OCP\Files\Node $n): ?bool {
	if (!$n->isReadable()) {
		return null;
	}
	$st = $n->getStorage();
	if (!$st->instanceOfStorage(\OCP\Files\Storage\ISharedStorage::class)) {
		return true;
	}
	$share = $st->getShare();
	$exp = $share->getExpirationDate();
	if ($exp !== null && $exp <= new DateTimeImmutable()) {
		return null;
	}
	return $share->getAttributes()?->getAttribute('permissions', 'download') !== false;
};
$best = static function (array $nodes) use ($judge): ?bool {      // null: none usable; else snippet allowed?
	$res = null;
	foreach ($nodes as $n) {
		$v = $judge($n);
		if ($v === true) {
			return true;
		}
		if ($v === false) {
			$res = false;
		}
	}
	return $res;
};
$parentOf = static fn (int $id): ?int => ($v = $db->executeQuery('SELECT parent FROM *PREFIX*filecache WHERE fileid = ?', [$id])->fetchOne()) === false ? null : (int)$v;

$t1 = hrtime(true);
$decisions = [];
$readable = [];                                              // file id => snippet allowed

if ($variant === 'prod') {
	$recheck = $c->get(\OCA\BuscadorDocumental\Search\Recheck::class);
	$out = $recheck->filter($uid, $ids);
	foreach ($ids as $id) {
		$decisions[$id] = isset($out[$id]) ? ($out[$id]->snippetAllowed ? 'o' : 's') : 'd';   // d: denied or excluded, see below
	}
	$prodDropped = $recheck->dropped();
} else {
	if ($variant === 'V3') {
		$byId = [];
		$q = new SearchQuery(new SearchComparison(ISearchComparison::COMPARE_IN, 'fileid', $ids), count($ids) * 4, 0, [], $c->get(\OCP\IUserSession::class)->getUser() ?? $c->get(\OCP\IUserManager::class)->get($uid));
		foreach ($userFolder->search($q) as $n) {
			$byId[$n->getId()][] = $n;
		}
	}
	foreach ($ids as $id) {
		$nodes = $variant === 'V3' ? ($byId[$id] ?? []) : $userFolder->getById($id);
		$v = $best($nodes);
		if ($v === null) {
			$decisions[$id] = 'd';
		} else {
			$readable[$id] = $v;
		}
	}
	$excluded = [];
	if ($variant === 'V0' || $variant === 'V1') {
		$memo = [];
		$chainExcluded = static function (int $id) use (&$chainExcluded, &$memo, $mapper, $tagIds, $parentOf, $variant): bool {
			if ($variant === 'V1' && isset($memo[$id])) {
				return $memo[$id];
			}
			$tagged = false;
			foreach ($tagIds as $t) {
				$tagged = $tagged || $mapper->haveTag([(string)$id], 'files', $t);
			}
			$p = $parentOf($id);
			$res = $tagged || ($p !== null && $p > 0 && $p !== $id && $chainExcluded($p));
			if ($variant === 'V1') {
				$memo[$id] = $res;
			}
			return $res;
		};
		foreach (array_keys($readable) as $id) {
			$excluded[$id] = $chainExcluded($id);
		}
	} else {                                                     // V2, V3: parents memoed, bulk tag lookup
		$parents = [];
		$chains = [];
		$all = [];
		foreach (array_keys($readable) as $id) {
			$chain = [$id];
			for ($cur = $id, $d = 0; $d < 64; $d++) {
				if (!array_key_exists($cur, $parents)) {
					$parents[$cur] = $parentOf($cur);
				}
				$p = $parents[$cur];
				if ($p === null || $p <= 0 || $p === $cur) {
					break;
				}
				$chain[] = $p;
				$cur = $p;
			}
			$chains[$id] = $chain;
			foreach ($chain as $x) {
				$all[$x] = true;
			}
		}
		$tagged = $mapper->getTagIdsForObjects(array_map('strval', array_keys($all)), 'files');
		foreach ($chains as $id => $chain) {
			$excluded[$id] = false;
			foreach ($chain as $x) {
				if (array_intersect($tagIds, array_map('strval', $tagged[(string)$x] ?? [])) !== []) {
					$excluded[$id] = true;
					break;
				}
			}
		}
	}
	foreach ($readable as $id => $snippet) {
		$decisions[$id] = $excluded[$id] ? 'x' : ($snippet ? 'o' : 's');
	}
}
$check = (hrtime(true) - $t1) / 1e6;

if ($variant === 'prod') {                                   // prod does not tell denied from excluded: split them by the V2 rule
	$fixtureKind = array_column($hits, 'kind', 'file_id');
	foreach ($decisions as $id => $d) {
		if ($d === 'd' && ($fixtureKind[$id] ?? '') === 'tagged') {
			$decisions[$id] = 'x?';
		}
	}
}
ksort($decisions);
$count = static fn (array $codes): int => count(array_filter($decisions, static fn ($d) => in_array($d, $codes, true)));
echo json_encode(['uid' => $uid, 'variant' => $variant, 'setup_ms' => round($setup, 1), 'check_ms' => round($check, 1),
	'total_ms' => round($setup + $check, 1), 'ok' => $count(['o', 's']), 'snippet_off' => $count(['s']),
	'denied_or_excluded' => $count(['d', 'x', 'x?']), 'excluded' => $count(['x']), 'decisions' => $decisions]), "\n";
