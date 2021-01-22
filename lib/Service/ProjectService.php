<?php

/**
 * Nextcloud - cospend
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2019
 */

namespace OCA\Cospend\Service;

use OCP\IL10N;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCP\DB\QueryBuilder\IQueryBuilder;

use OC\Archive\ZIP;
use OCP\IGroupManager;
use OCP\IAvatarManager;

use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\IServerContainer;
use OCP\IDBConnection;

use OCA\Cospend\Activity\ActivityManager;
use OCA\Cospend\Db\ProjectMapper;
use OCA\Cospend\Db\BillMapper;

require_once __DIR__ . '/const.php';

function endswith($string, $test) {
    $strlen = strlen($string);
    $testlen = strlen($test);
    if ($testlen > $strlen) return false;
    return substr_compare($string, $test, $strlen - $testlen, $testlen) === 0;
}

function slugify($text) {
    $str = strval($text);
    $str = \preg_replace('/^\s+|\s+$/', '', $str); // trim
    $str = \strtolower($str);

    $swaps = [
        '0' => ['°', '₀', '۰', '０'],
        '1' => ['¹', '₁', '۱', '１'],
        '2' => ['²', '₂', '۲', '２'],
        '3' => ['³', '₃', '۳', '３'],
        '4' => ['⁴', '₄', '۴', '٤', '４'],
        '5' => ['⁵', '₅', '۵', '٥', '５'],
        '6' => ['⁶', '₆', '۶', '٦', '６'],
        '7' => ['⁷', '₇', '۷', '７'],
        '8' => ['⁸', '₈', '۸', '８'],
        '9' => ['⁹', '₉', '۹', '９'],
        'a' => ['à', 'á', 'ả', 'ã', 'ạ', 'ă', 'ắ', 'ằ', 'ẳ', 'ẵ', 'ặ', 'â', 'ấ', 'ầ', 'ẩ', 'ẫ', 'ậ', 'ā', 'ą', 'å', 'α', 'ά', 'ἀ', 'ἁ', 'ἂ', 'ἃ', 'ἄ', 'ἅ', 'ἆ', 'ἇ', 'ᾀ', 'ᾁ', 'ᾂ', 'ᾃ', 'ᾄ', 'ᾅ', 'ᾆ', 'ᾇ', 'ὰ', 'ά', 'ᾰ', 'ᾱ', 'ᾲ', 'ᾳ', 'ᾴ', 'ᾶ', 'ᾷ', 'а', 'أ', 'အ', 'ာ', 'ါ', 'ǻ', 'ǎ', 'ª', 'ა', 'अ', 'ا', 'ａ', 'ä'],
        'b' => ['б', 'β', 'ب', 'ဗ', 'ბ', 'ｂ'],
        'c' => ['ç', 'ć', 'č', 'ĉ', 'ċ', 'ｃ'],
        'd' => ['ď', 'ð', 'đ', 'ƌ', 'ȡ', 'ɖ', 'ɗ', 'ᵭ', 'ᶁ', 'ᶑ', 'д', 'δ', 'د', 'ض', 'ဍ', 'ဒ', 'დ', 'ｄ'],
        'e' => ['é', 'è', 'ẻ', 'ẽ', 'ẹ', 'ê', 'ế', 'ề', 'ể', 'ễ', 'ệ', 'ë', 'ē', 'ę', 'ě', 'ĕ', 'ė', 'ε', 'έ', 'ἐ', 'ἑ', 'ἒ', 'ἓ', 'ἔ', 'ἕ', 'ὲ', 'έ', 'е', 'ё', 'э', 'є', 'ə', 'ဧ', 'ေ', 'ဲ', 'ე', 'ए', 'إ', 'ئ', 'ｅ'],
        'f' => ['ф', 'φ', 'ف', 'ƒ', 'ფ', 'ｆ'],
        'g' => ['ĝ', 'ğ', 'ġ', 'ģ', 'г', 'ґ', 'γ', 'ဂ', 'გ', 'گ', 'ｇ'],
        'h' => ['ĥ', 'ħ', 'η', 'ή', 'ح', 'ه', 'ဟ', 'ှ', 'ჰ', 'ｈ'],
        'i' => ['í', 'ì', 'ỉ', 'ĩ', 'ị', 'î', 'ï', 'ī', 'ĭ', 'į', 'ı', 'ι', 'ί', 'ϊ', 'ΐ', 'ἰ', 'ἱ', 'ἲ', 'ἳ', 'ἴ', 'ἵ', 'ἶ', 'ἷ', 'ὶ', 'ί', 'ῐ', 'ῑ', 'ῒ', 'ΐ', 'ῖ', 'ῗ', 'і', 'ї', 'и', 'ဣ', 'ိ', 'ီ', 'ည်', 'ǐ', 'ი', 'इ', 'ی', 'ｉ'],
        'j' => ['ĵ', 'ј', 'Ј', 'ჯ', 'ج', 'ｊ'],
        'k' => ['ķ', 'ĸ', 'к', 'κ', 'Ķ', 'ق', 'ك', 'က', 'კ', 'ქ', 'ک', 'ｋ'],
        'l' => ['ł', 'ľ', 'ĺ', 'ļ', 'ŀ', 'л', 'λ', 'ل', 'လ', 'ლ', 'ｌ'],
        'm' => ['м', 'μ', 'م', 'မ', 'მ', 'ｍ'],
        'n' => ['ñ', 'ń', 'ň', 'ņ', 'ŉ', 'ŋ', 'ν', 'н', 'ن', 'န', 'ნ', 'ｎ'],
        'o' => ['ó', 'ò', 'ỏ', 'õ', 'ọ', 'ô', 'ố', 'ồ', 'ổ', 'ỗ', 'ộ', 'ơ', 'ớ', 'ờ', 'ở', 'ỡ', 'ợ', 'ø', 'ō', 'ő', 'ŏ', 'ο', 'ὀ', 'ὁ', 'ὂ', 'ὃ', 'ὄ', 'ὅ', 'ὸ', 'ό', 'о', 'و', 'θ', 'ို', 'ǒ', 'ǿ', 'º', 'ო', 'ओ', 'ｏ', 'ö'],
        'p' => ['п', 'π', 'ပ', 'პ', 'پ', 'ｐ'],
        'q' => ['ყ', 'ｑ'],
        'r' => ['ŕ', 'ř', 'ŗ', 'р', 'ρ', 'ر', 'რ', 'ｒ'],
        's' => ['ś', 'š', 'ş', 'с', 'σ', 'ș', 'ς', 'س', 'ص', 'စ', 'ſ', 'ს', 'ｓ'],
        't' => ['ť', 'ţ', 'т', 'τ', 'ț', 'ت', 'ط', 'ဋ', 'တ', 'ŧ', 'თ', 'ტ', 'ｔ'],
        'u' => ['ú', 'ù', 'ủ', 'ũ', 'ụ', 'ư', 'ứ', 'ừ', 'ử', 'ữ', 'ự', 'û', 'ū', 'ů', 'ű', 'ŭ', 'ų', 'µ', 'у', 'ဉ', 'ု', 'ူ', 'ǔ', 'ǖ', 'ǘ', 'ǚ', 'ǜ', 'უ', 'उ', 'ｕ', 'ў', 'ü'],
        'v' => ['в', 'ვ', 'ϐ', 'ｖ'],
        'w' => ['ŵ', 'ω', 'ώ', 'ဝ', 'ွ', 'ｗ'],
        'x' => ['χ', 'ξ', 'ｘ'],
        'y' => ['ý', 'ỳ', 'ỷ', 'ỹ', 'ỵ', 'ÿ', 'ŷ', 'й', 'ы', 'υ', 'ϋ', 'ύ', 'ΰ', 'ي', 'ယ', 'ｙ'],
        'z' => ['ź', 'ž', 'ż', 'з', 'ζ', 'ز', 'ဇ', 'ზ', 'ｚ'],
        'aa' => ['ع', 'आ', 'آ'],
        'ae' => ['æ', 'ǽ'],
        'ai' => ['ऐ'],
        'ch' => ['ч', 'ჩ', 'ჭ', 'چ'],
        'dj' => ['ђ', 'đ'],
        'dz' => ['џ', 'ძ'],
        'ei' => ['ऍ'],
        'gh' => ['غ', 'ღ'],
        'ii' => ['ई'],
        'ij' => ['ĳ'],
        'kh' => ['х', 'خ', 'ხ'],
        'lj' => ['љ'],
        'nj' => ['њ'],
        'oe' => ['ö', 'œ', 'ؤ'],
        'oi' => ['ऑ'],
        'oii' => ['ऒ'],
        'ps' => ['ψ'],
        'sh' => ['ш', 'შ', 'ش'],
        'shch' => ['щ'],
        'ss' => ['ß'],
        'sx' => ['ŝ'],
        'th' => ['þ', 'ϑ', 'ث', 'ذ', 'ظ'],
        'ts' => ['ц', 'ც', 'წ'],
        'ue' => ['ü'],
        'uu' => ['ऊ'],
        'ya' => ['я'],
        'yu' => ['ю'],
        'zh' => ['ж', 'ჟ', 'ژ'],
        '(c)' => ['©'],
        'A' => ['Á', 'À', 'Ả', 'Ã', 'Ạ', 'Ă', 'Ắ', 'Ằ', 'Ẳ', 'Ẵ', 'Ặ', 'Â', 'Ấ', 'Ầ', 'Ẩ', 'Ẫ', 'Ậ', 'Å', 'Ā', 'Ą', 'Α', 'Ά', 'Ἀ', 'Ἁ', 'Ἂ', 'Ἃ', 'Ἄ', 'Ἅ', 'Ἆ', 'Ἇ', 'ᾈ', 'ᾉ', 'ᾊ', 'ᾋ', 'ᾌ', 'ᾍ', 'ᾎ', 'ᾏ', 'Ᾰ', 'Ᾱ', 'Ὰ', 'Ά', 'ᾼ', 'А', 'Ǻ', 'Ǎ', 'Ａ', 'Ä'],
        'B' => ['Б', 'Β', 'ब', 'Ｂ'],
        'C' => ['Ç', 'Ć', 'Č', 'Ĉ', 'Ċ', 'Ｃ'],
        'D' => ['Ď', 'Ð', 'Đ', 'Ɖ', 'Ɗ', 'Ƌ', 'ᴅ', 'ᴆ', 'Д', 'Δ', 'Ｄ'],
        'E' => ['É', 'È', 'Ẻ', 'Ẽ', 'Ẹ', 'Ê', 'Ế', 'Ề', 'Ể', 'Ễ', 'Ệ', 'Ë', 'Ē', 'Ę', 'Ě', 'Ĕ', 'Ė', 'Ε', 'Έ', 'Ἐ', 'Ἑ', 'Ἒ', 'Ἓ', 'Ἔ', 'Ἕ', 'Έ', 'Ὲ', 'Е', 'Ё', 'Э', 'Є', 'Ə', 'Ｅ'],
        'F' => ['Ф', 'Φ', 'Ｆ'],
        'G' => ['Ğ', 'Ġ', 'Ģ', 'Г', 'Ґ', 'Γ', 'Ｇ'],
        'H' => ['Η', 'Ή', 'Ħ', 'Ｈ'],
        'I' => ['Í', 'Ì', 'Ỉ', 'Ĩ', 'Ị', 'Î', 'Ï', 'Ī', 'Ĭ', 'Į', 'İ', 'Ι', 'Ί', 'Ϊ', 'Ἰ', 'Ἱ', 'Ἳ', 'Ἴ', 'Ἵ', 'Ἶ', 'Ἷ', 'Ῐ', 'Ῑ', 'Ὶ', 'Ί', 'И', 'І', 'Ї', 'Ǐ', 'ϒ', 'Ｉ'],
        'J' => ['Ｊ'],
        'K' => ['К', 'Κ', 'Ｋ'],
        'L' => ['Ĺ', 'Ł', 'Л', 'Λ', 'Ļ', 'Ľ', 'Ŀ', 'ल', 'Ｌ'],
        'M' => ['М', 'Μ', 'Ｍ'],
        'N' => ['Ń', 'Ñ', 'Ň', 'Ņ', 'Ŋ', 'Н', 'Ν', 'Ｎ'],
        'O' => ['Ó', 'Ò', 'Ỏ', 'Õ', 'Ọ', 'Ô', 'Ố', 'Ồ', 'Ổ', 'Ỗ', 'Ộ', 'Ơ', 'Ớ', 'Ờ', 'Ở', 'Ỡ', 'Ợ', 'Ø', 'Ō', 'Ő', 'Ŏ', 'Ο', 'Ό', 'Ὀ', 'Ὁ', 'Ὂ', 'Ὃ', 'Ὄ', 'Ὅ', 'Ὸ', 'Ό', 'О', 'Θ', 'Ө', 'Ǒ', 'Ǿ', 'Ｏ', 'Ö'],
        'P' => ['П', 'Π', 'Ｐ'],
        'Q' => ['Ｑ'],
        'R' => ['Ř', 'Ŕ', 'Р', 'Ρ', 'Ŗ', 'Ｒ'],
        'S' => ['Ş', 'Ŝ', 'Ș', 'Š', 'Ś', 'С', 'Σ', 'Ｓ'],
        'T' => ['Ť', 'Ţ', 'Ŧ', 'Ț', 'Т', 'Τ', 'Ｔ'],
        'U' => ['Ú', 'Ù', 'Ủ', 'Ũ', 'Ụ', 'Ư', 'Ứ', 'Ừ', 'Ử', 'Ữ', 'Ự', 'Û', 'Ū', 'Ů', 'Ű', 'Ŭ', 'Ų', 'У', 'Ǔ', 'Ǖ', 'Ǘ', 'Ǚ', 'Ǜ', 'Ｕ', 'Ў', 'Ü'],
        'V' => ['В', 'Ｖ'],
        'W' => ['Ω', 'Ώ', 'Ŵ', 'Ｗ'],
        'X' => ['Χ', 'Ξ', 'Ｘ'],
        'Y' => ['Ý', 'Ỳ', 'Ỷ', 'Ỹ', 'Ỵ', 'Ÿ', 'Ῠ', 'Ῡ', 'Ὺ', 'Ύ', 'Ы', 'Й', 'Υ', 'Ϋ', 'Ŷ', 'Ｙ'],
        'Z' => ['Ź', 'Ž', 'Ż', 'З', 'Ζ', 'Ｚ'],
        'AE' => ['Æ', 'Ǽ'],
        'Ch' => ['Ч'],
        'Dj' => ['Ђ'],
        'Dz' => ['Џ'],
        'Gx' => ['Ĝ'],
        'Hx' => ['Ĥ'],
        'Ij' => ['Ĳ'],
        'Jx' => ['Ĵ'],
        'Kh' => ['Х'],
        'Lj' => ['Љ'],
        'Nj' => ['Њ'],
        'Oe' => ['Œ'],
        'Ps' => ['Ψ'],
        'Sh' => ['Ш'],
        'Shch' => ['Щ'],
        'Ss' => ['ẞ'],
        'Th' => ['Þ'],
        'Ts' => ['Ц'],
        'Ya' => ['Я'],
        'Yu' => ['Ю'],
        'Zh' => ['Ж']
    ];

    foreach ($swaps as $swap => $chars) {
        foreach ($chars as $char) {
            $str = \preg_replace('/'.$char.'/', $swap, $str);
        }
    }
    $str = \preg_replace('/[^a-z0-9 -]/', '_', $str);
    $str = \preg_replace('/\s+/', '-', $str);
    $str = \preg_replace('/-+/', '-', $str);
    $str = \preg_replace('/^-+/', '', $str);
    $str = \preg_replace('/-+$/', '', $str);
    return $str;
}

class ProjectService {

    private $l10n;
    private $logger;
    private $config;
    private $qb;
    private $dbconnection;

    public function __construct (LoggerInterface $logger,
                                IL10N $l10n,
                                IConfig $config,
                                ProjectMapper $projectMapper,
                                BillMapper $billMapper,
                                ActivityManager $activityManager,
                                IAvatarManager $avatarManager,
                                IManager $shareManager,
                                IUserManager $userManager,
                                IGroupManager $groupManager,
                                IDBConnection $dbconnection) {
        $this->trans = $l10n;
        $this->config = $config;
        $this->logger = $logger;
        $this->dbconnection = $dbconnection;
        $this->qb = $dbconnection->getQueryBuilder();
        $this->projectMapper = $projectMapper;
        $this->billMapper = $billMapper;
        $this->activityManager = $activityManager;
        $this->avatarManager = $avatarManager;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
        $this->shareManager = $shareManager;

        $this->defaultCategoryNames = [
            '-1' => $this->trans->t('Grocery'),
            '-2' => $this->trans->t('Bar/Party'),
            '-3' => $this->trans->t('Rent'),
            '-4' => $this->trans->t('Bill'),
            '-5' => $this->trans->t('Excursion/Culture'),
            '-6' => $this->trans->t('Health'),
            '-10' => $this->trans->t('Shopping'),
            '-12' => $this->trans->t('Restaurant'),
            '-13' => $this->trans->t('Accommodation'),
            '-14' => $this->trans->t('Transport'),
            '-15' => $this->trans->t('Sport')
        ];
        $this->defaultCategoryIcons = [
            '-1'  => '🛒',
            '-2'  => '🎉',
            '-3'  => '🏠',
            '-4'  => '🌩',
            '-5'  => '🚸',
            '-6'  => '💚',
            '-10' => '🛍',
            '-12' => '🍴',
            '-13' => '🛌',
            '-14' => '🚌',
            '-15' => '🎾'
        ];
        $this->defaultCategoryColors = [
            '-1'  => '#ffaa00',
            '-2'  => '#aa55ff',
            '-3'  => '#da8733',
            '-4'  => '#4aa6b0',
            '-5'  => '#0055ff',
            '-6'  => '#bf090c',
            '-10' => '#e167d1',
            '-12' => '#d0d5e1',
            '-13' => '#5de1a3',
            '-14' => '#6f2ee1',
            '-15' => '#69e177'
        ];

        $this->hardCodedCategoryNames = [
            '-11' => $this->trans->t('Reimbursement'),
        ];

    }

    /**
     * check if user owns the project
     * or if the project is shared with the user
     */
    public function userCanAccessProject($userid, $projectid) {
        $projectInfo = $this->getProjectInfo($projectid);
        if ($projectInfo !== null) {
            // does the user own the project ?
            if ($projectInfo['userid'] === $userid) {
                return true;
            }
            else {
                $qb = $this->dbconnection->getQueryBuilder();
                // is the project shared with the user ?
                $qb->select('userid', 'projectid')
                    ->from('cospend_shares', 's')
                    ->where(
                        $qb->expr()->eq('type', $qb->createNamedParameter('u', IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('userid', $qb->createNamedParameter($userid, IQueryBuilder::PARAM_STR))
                    );
                $req = $qb->execute();
                $dbProjectId = null;
                while ($row = $req->fetch()) {
                    $dbProjectId = $row['projectid'];
                    break;
                }
                $req->closeCursor();
                $qb = $qb->resetQueryParts();

                if ($dbProjectId !== null) {
                    return true;
                }
                else {
                    // if not, is the project shared with a group containing the user?
                    $userO = $this->userManager->get($userid);
                    $accessWithGroup = null;

                    $qb->select('userid')
                        ->from('cospend_shares', 's')
                        ->where(
                            $qb->expr()->eq('type', $qb->createNamedParameter('g', IQueryBuilder::PARAM_STR))
                        )
                        ->andWhere(
                            $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                        );
                    $req = $qb->execute();
                    while ($row = $req->fetch()){
                        $groupId = $row['userid'];
                        if ($this->groupManager->groupExists($groupId) && $this->groupManager->get($groupId)->inGroup($userO)) {
                            $accessWithGroup = $groupId;
                            break;
                        }
                    }
                    $req->closeCursor();
                    $qb = $qb->resetQueryParts();

                    if ($accessWithGroup !== null) {
                        return true;
                    }
                    else {
                        // if not, are circles enabled and is the project shared with a circle containing the user?
                        $circlesEnabled = \OC::$server->getAppManager()->isEnabledForUser('circles');
                        if ($circlesEnabled) {
                            $dbCircleId = null;

                            $qb->select('userid')
                                ->from('cospend_shares', 's')
                                ->where(
                                    $qb->expr()->eq('type', $qb->createNamedParameter('c', IQueryBuilder::PARAM_STR))
                                )
                                ->andWhere(
                                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                                );
                            $req = $qb->execute();
                            while ($row = $req->fetch()) {
                                $circleId = $row['userid'];
                                if ($this->isUserInCircle($userid, $circleId)) {
                                    return true;
                                }
                            }
                        }
                        return false;
                    }

                }
            }
        }
        else {
            return false;
        }
    }

    public function getUserMaxAccessLevel($userid, $projectid) {
        $result = 0;
        $projectInfo = $this->getProjectInfo($projectid);
        if ($projectInfo !== null) {
            // does the user own the project ?
            if ($projectInfo['userid'] === $userid) {
                return ACCESS_ADMIN;
            }
            else {
                $qb = $this->dbconnection->getQueryBuilder();
                // is the project shared with the user ?
                $qb->select('userid', 'projectid', 'accesslevel')
                    ->from('cospend_shares', 's')
                    ->where(
                        $qb->expr()->eq('type', $qb->createNamedParameter('u', IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('userid', $qb->createNamedParameter($userid, IQueryBuilder::PARAM_STR))
                    );
                $req = $qb->execute();
                $dbProjectId = null;
                $dbAccessLevel = null;
                while ($row = $req->fetch()) {
                    $dbProjectId = $row['projectid'];
                    $dbAccessLevel = intval($row['accesslevel']);
                    break;
                }
                $req->closeCursor();
                $qb = $qb->resetQueryParts();

                if ($dbProjectId !== null && $dbAccessLevel > $result) {
                    $result = $dbAccessLevel;
                }

                // is the project shared with a group containing the user?
                $userO = $this->userManager->get($userid);
                $accessWithGroup = null;

                $qb->select('userid', 'accesslevel')
                    ->from('cospend_shares', 's')
                    ->where(
                        $qb->expr()->eq('type', $qb->createNamedParameter('g', IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                    );
                $req = $qb->execute();
                while ($row = $req->fetch()){
                    $groupId = $row['userid'];
                    $dbAccessLevel = intval($row['accesslevel']);
                    if ($this->groupManager->groupExists($groupId)
                        && $this->groupManager->get($groupId)->inGroup($userO)
                        && $dbAccessLevel > $result
                    ) {
                        $result = $dbAccessLevel;
                    }
                }
                $req->closeCursor();
                $qb = $qb->resetQueryParts();

                // are circles enabled and is the project shared with a circle containing the user
                $circlesEnabled = \OC::$server->getAppManager()->isEnabledForUser('circles');
                if ($circlesEnabled) {
                    $dbCircleId = null;

                    $qb->select('userid', 'accesslevel')
                        ->from('cospend_shares', 's')
                        ->where(
                            $qb->expr()->eq('type', $qb->createNamedParameter('c', IQueryBuilder::PARAM_STR))
                        )
                        ->andWhere(
                            $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                        );
                    $req = $qb->execute();
                    while ($row = $req->fetch()) {
                        $circleId = $row['userid'];
                        $dbAccessLevel = intval($row['accesslevel']);
                        if ($this->isUserInCircle($userid, $circleId) && $dbAccessLevel > $result) {
                            $result = $dbAccessLevel;
                        }
                    }
                }
            }
        }

        return $result;
    }

    public function getGuestAccessLevel($projectid) {
        $projectInfo = $this->getProjectInfo($projectid);
        if ($projectInfo !== null) {
            return intval($projectInfo['guestaccesslevel']);
        }
        else {
            return false;
        }
    }

    public function getShareAccessLevel($projectid, $shid) {
        $result = 0;
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('accesslevel')
           ->from('cospend_shares', 's')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()){
            $result = intval($row['accesslevel']);
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $result;
    }

    public function createProject($name, $id, $password, $contact_email, $userid='',
                                  $createDefaultCategories=true) {
        $qb = $this->dbconnection->getQueryBuilder();

        $qb->select('id')
           ->from('cospend_projects', 'p')
           ->where(
               $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();

        $dbid = null;
        while ($row = $req->fetch()){
            $dbid = $row['id'];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        if ($dbid === null) {
            // check if id is valid
            if (strpos($id, '/') !== false) {
                return ['message' => $this->trans->t('Invalid project id')];
            }
            $dbPassword = '';
            if ($password !== null && $password !== '') {
                $dbPassword = password_hash($password, PASSWORD_DEFAULT);
            }
            if ($contact_email === null) {
                $contact_email = '';
            }
            $ts = (new \DateTime())->getTimestamp();
            $qb->insert('cospend_projects')
                ->values([
                    'userid' => $qb->createNamedParameter($userid, IQueryBuilder::PARAM_STR),
                    'id' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR),
                    'name' => $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR),
                    'password' => $qb->createNamedParameter($dbPassword, IQueryBuilder::PARAM_STR),
                    'email' => $qb->createNamedParameter($contact_email, IQueryBuilder::PARAM_STR),
                    'lastchanged' => $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT)
                ]);
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            // create default categories
            if ($createDefaultCategories) {
                foreach ($this->defaultCategoryNames as $strId => $name) {
                    $icon = urlencode($this->defaultCategoryIcons[$strId]);
                    $color = $this->defaultCategoryColors[$strId];
                    $qb->insert('cospend_project_categories')
                        ->values([
                            'projectid' => $qb->createNamedParameter($id, IQueryBuilder::PARAM_STR),
                            'encoded_icon' => $qb->createNamedParameter($icon, IQueryBuilder::PARAM_STR),
                            'color' => $qb->createNamedParameter($color, IQueryBuilder::PARAM_STR),
                            'name' => $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR)
                        ]);
                    $req = $qb->execute();
                    $qb = $qb->resetQueryParts();
                }
            }

            return $id;
        }
        else {
            return ['message' => $this->trans->t('A project with id "%1$s" already exists', [$id])];
        }
    }

    public function deleteProject($projectid) {
        $projectToDelete = $this->getProjectById($projectid);
        if ($projectToDelete !== null) {
            $qb = $this->dbconnection->getQueryBuilder();

            // delete project bills
            $bills = $this->getBills($projectid);
            foreach ($bills as $bill) {
                $this->deleteBillOwersOfBill($bill['id']);
            }

            $qb->delete('cospend_bills')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            // delete project members
            $qb->delete('cospend_members')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            // delete shares
            $qb->delete('cospend_shares')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            // delete currencies
            $qb->delete('cospend_currencies')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            // delete categories
            $qb->delete('cospend_project_categories')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            // delete project
            $qb->delete('cospend_projects')
                ->where(
                    $qb->expr()->eq('id', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            return 'DELETED';
        }
        else {
            return [$this->trans->t('Not Found')];
        }
    }

    public function getProjectInfo($projectid) {
        $projectInfo = null;

        $qb = $this->dbconnection->getQueryBuilder();

        $qb->select('id', 'password', 'name', 'email', 'userid', 'lastchanged', 'guestaccesslevel',
                    'autoexport', 'currencyname', 'deletiondisabled', 'categorysort')
           ->from('cospend_projects', 'p')
           ->where(
               $qb->expr()->eq('id', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();

        $dbProjectId = null;
        $dbPassword = null;
        while ($row = $req->fetch()){
            $dbProjectId = $row['id'];
            $dbPassword = $row['password'];
            $dbName = $row['name'];
            $dbEmail= $row['email'];
            $dbUserId = $row['userid'];
            $dbGuestAccessLevel = intval($row['guestaccesslevel']);
            $dbLastchanged = intval($row['lastchanged']);
            $dbAutoexport= $row['autoexport'];
            $dbCurrencyName = $row['currencyname'];
            $dbDeletionDisabled = intval($row['deletiondisabled']) === 1;
            $dbCategorySort= $row['categorysort'];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        if ($dbProjectId !== null) {
            $smallStats = $this->getSmallStats($dbProjectId);
            $members = $this->getMembers($dbProjectId, 'lowername');
            $activeMembers = [];
            foreach ($members as $member) {
                if ($member['activated']) {
                    array_push($activeMembers, $member);
                }
            }
            $balance = $this->getBalance($dbProjectId);
            $currencies = $this->getCurrencies($dbProjectId);
            $categories = $this->getCategories($dbProjectId);
            // get all shares
            $userShares = $this->getUserShares($dbProjectId);
            $groupShares = $this->getGroupShares($dbProjectId);
            $circleShares = $this->getCircleShares($dbProjectId);
            $publicShares = $this->getPublicShares($dbProjectId);
            $shares = array_merge($userShares, $groupShares, $circleShares, $publicShares);

            $projectInfo = [
                'userid' => $dbUserId,
                'name' => $dbName,
                'contact_email' => $dbEmail,
                'id' => $dbProjectId,
                'guestaccesslevel' => $dbGuestAccessLevel,
                'autoexport' => $dbAutoexport,
                'currencyname' => $dbCurrencyName,
                'lastchanged' => $dbLastchanged,
                'active_members' => $activeMembers,
                'members' => $members,
                'balance' => $balance,
                'nb_bills' => $smallStats['nb_bills'],
                'total_spent' => $smallStats['total_spent'],
                'shares' => $shares,
                'currencies' => $currencies,
                'categories' => $categories,
                'deletion_disabled' => $dbDeletionDisabled,
                'categorysort' => $dbCategorySort,
            ];
        }

        return $projectInfo;
    }

    private function getSmallStats(string $projectId): array {
        $nbBills = 0;
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'count_bills')
           ->from('cospend_bills')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()) {
            $nbBills = (int) $row['count_bills'];
        }
        $qb = $qb->resetQueryParts();

        $totalSpent = 0;
        $qb->selectAlias($qb->createFunction('SUM(amount)'), 'sum_amount')
           ->from('cospend_bills')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()) {
            $totalSpent = (int) $row['sum_amount'];
        }
        $qb = $qb->resetQueryParts();
        return [
            'nb_bills' => $nbBills,
            'total_spent' => $totalSpent,
        ];
    }

    public function getProjectStatistics($projectId, $memberOrder=null, $tsMin=null, $tsMax=null,
                                          $paymentMode=null, $category=null, $amountMin=null, $amountMax=null,
                                          $showDisabled='1', $currencyId=null) {
        $membersWeight = [];
        $membersNbBills = [];
        $membersBalance = [];
        $membersFilteredBalance = [];
        $membersPaid = [
            'total' => [],
        ];
        $membersSpent = [];
        $membersPaidFor = [];

        $showDisabled = ($showDisabled === '1');

        $currency = null;
        if ($currencyId !== null && intval($currencyId) !== 0) {
            $currency = $this->getCurrency($projectId, $currencyId);
        }

        $projectCategories = $this->getCategories($projectId);

        // get the real global balances with no filters
        $balances = $this->getBalance($projectId);

        $members = $this->getMembers($projectId, $memberOrder);
        foreach ($members as $member) {
            $memberId = $member['id'];
            $memberWeight = $member['weight'];
            $membersWeight[$memberId] = $memberWeight;
            $membersNbBills[$memberId] = 0;
            $membersBalance[$memberId] = $balances[$memberId];
            $membersFilteredBalance[$memberId] = 0.0;
            $membersPaid[$memberId] = 0.0;
            $membersSpent[$memberId] = 0.0;
            $membersPaidFor[$memberId] = [];
            foreach ($members as $m) {
                $membersPaidFor[$memberId][$m['id']] = 0.0;
            }
            $membersPaidFor['total'][$memberId] = 0.0;
        }

        // build list of members to display
        $membersToDisplay = [];
        $allMembersIds = [];
        foreach ($members as $member) {
            $memberId = $member['id'];
            $allMembersIds[] = $memberId;
            // only take enabled members or those with non-zero balance
            $mBalance = floatval($membersBalance[$memberId]);
            if ($showDisabled || $member['activated'] || $mBalance >= 0.01 || $mBalance <= -0.01) {
                $membersToDisplay[$memberId] = $member;
            }
        }

        // compute stats
        $bills = $this->getBills($projectId, $tsMin, $tsMax, $paymentMode, $category, $amountMin, $amountMax);
        // compute classic stats
        foreach ($bills as $bill) {
            $payerId = $bill['payer_id'];
            $amount = $bill['amount'];
            $owers = $bill['owers'];

            $membersNbBills[$payerId]++;
            $membersFilteredBalance[$payerId] += $amount;
            $membersPaid[$payerId] += $amount;

            $nbOwerShares = 0.0;
            foreach ($owers as $ower) {
                $owerWeight = $ower['weight'];
                if ($owerWeight === 0.0) {
                    $owerWeight = 1.0;
                }
                $nbOwerShares += $owerWeight;
            }
            foreach ($owers as $ower) {
                $owerWeight = $ower['weight'];
                if ($owerWeight === 0.0) {
                    $owerWeight = 1.0;
                }
                $owerId = $ower['id'];
                $spent = $amount / $nbOwerShares * $owerWeight;
                $membersFilteredBalance[$owerId] -= $spent;
                $membersSpent[$owerId] += $spent;
                // membersPaidFor
                $membersPaidFor[$payerId][$owerId] += $spent;
                $membersPaidFor['total'][$owerId] += $spent;
            }
        }
        foreach ($members as $member) {
            $memberId = $member['id'];
            $membersPaidFor[$memberId]['total'] = $membersPaid[$memberId];
        }

        // build global stats data
        $statistics = [];
        if ($currency === null) {
            foreach ($membersToDisplay as $memberId => $member) {
                $statistic = [
                    'balance' => $membersBalance[$memberId],
                    'filtered_balance' => $membersFilteredBalance[$memberId],
                    'paid' => $membersPaid[$memberId],
                    'spent' => $membersSpent[$memberId],
                    'member' => $member
                ];
                array_push($statistics, $statistic);
            }
        }
        else {
            foreach ($membersToDisplay as $memberId => $member) {
                $statistic = [
                    'balance' => ($membersBalance[$memberId] === 0.0) ? 0 : $membersBalance[$memberId] / $currency['exchange_rate'],
                    'filtered_balance' => ($membersFilteredBalance[$memberId] === 0.0) ? 0 : $membersFilteredBalance[$memberId] / $currency['exchange_rate'],
                    'paid' => ($membersPaid[$memberId] === 0.0) ? 0 : $membersPaid[$memberId] / $currency['exchange_rate'],
                    'spent' => ($membersSpent[$memberId] === 0.0) ? 0 : $membersSpent[$memberId] / $currency['exchange_rate'],
                    'member' => $member
                ];
                array_push($statistics, $statistic);
            }
        }

        // compute monthly stats
        $monthlyStats = [];
        $allMembersKey = 0;
        foreach ($bills as $bill) {
            $payerId = $bill['payer_id'];
            $amount = $bill['amount'];
            $date = \DateTime::createFromFormat('U', $bill['timestamp']);
            $month = $date->format('Y-m');
            if (!array_key_exists($month, $monthlyStats)) {
                $monthlyStats[$month] = [];
                foreach ($membersToDisplay as $memberId => $member) {
                    $monthlyStats[$month][$memberId] = 0;
                }
                $monthlyStats[$month][$allMembersKey] = 0;
            }

            if (array_key_exists($payerId, $membersToDisplay)) {
                $monthlyStats[$month][$payerId] += $amount;
                $monthlyStats[$month][$allMembersKey] += $amount;
            }
        }
        // monthly average
        $nbMonth = count(array_keys($monthlyStats));
        if ($nbMonth > 0) {
            $averageStats = [];
            foreach ($membersToDisplay as $memberId => $member) {
                $sum = 0;
                foreach ($monthlyStats as $month => $mStat) {
                    $sum += $monthlyStats[$month][$memberId];
                }
                $averageStats[$memberId] = $sum / $nbMonth;
            }
            // average for all members
            $sum = 0;
            foreach ($monthlyStats as $month => $mStat) {
                $sum += $monthlyStats[$month][$allMembersKey];
            }
            $averageStats[$allMembersKey] = $sum / $nbMonth;

            $averageKey = $this->trans->t('Average per month');
            $monthlyStats[$averageKey] = $averageStats;
        }
        // convert if necessary
        if ($currency !== null) {
            foreach ($monthlyStats as $month => $mStat) {
                foreach ($mStat as $mid => $val) {
                    $monthlyStats[$month][$mid] = ($monthlyStats[$month][$mid] === 0.0) ? 0 : $monthlyStats[$month][$mid] / $currency['exchange_rate'];
                }
            }
        }
        // compute category stats
        $categoryStats = [];
        foreach ($bills as $bill) {
            $categoryId = $bill['categoryid'];
            if (!array_key_exists(strval($categoryId), $this->hardCodedCategoryNames) and
                !array_key_exists(strval($categoryId), $projectCategories)
            ) {
                $categoryId = 0;
            }
            $amount = $bill['amount'];
            if (!array_key_exists($categoryId, $categoryStats)) {
                $categoryStats[$categoryId] = 0;
            }
            $categoryStats[$categoryId] += $amount;
        }
        // convert if necessary
        if ($currency !== null) {
            foreach ($categoryStats as $catId => $val) {
                $categoryStats[$catId] = ($val === 0.0) ? 0 : $val / $currency['exchange_rate'];
            }
        }
        // compute category per member stats
        $categoryMemberStats = [];
        foreach ($bills as $bill) {
            $payerId = $bill['payer_id'];
            $categoryId = $bill['categoryid'];
            if (!array_key_exists(strval($categoryId), $this->hardCodedCategoryNames) and
                !array_key_exists(strval($categoryId), $projectCategories)
            ) {
                $categoryId = 0;
            }
            $amount = $bill['amount'];
            if (!array_key_exists($categoryId, $categoryMemberStats)) {
                $categoryMemberStats[$categoryId] = [];
                foreach ($membersToDisplay as $memberId => $member) {
                    $categoryMemberStats[$categoryId][$memberId] = 0;
                }
            }
            if (array_key_exists($payerId, $membersToDisplay)) {
                $categoryMemberStats[$categoryId][$payerId] += $amount;
            }
        }
        // convert if necessary
        if ($currency !== null) {
            foreach ($categoryMemberStats as $catId => $mStat) {
                foreach ($mStat as $mid => $val) {
                    $categoryMemberStats[$catId][$mid] = ($val === 0.0) ? 0 : $val / $currency['exchange_rate'];
                }
            }
        }
        // compute category per month stats
        $categoryMonthlyStats = [];
        foreach ($bills as $bill) {
            $categoryId = $bill['categoryid'];
            $amount = $bill['amount'];
            $date = \DateTime::createFromFormat('U', $bill['timestamp']);
            $month = $date->format('Y-m');

            if (!array_key_exists($categoryId, $categoryMonthlyStats)) {
                $categoryMonthlyStats[$categoryId] = [];
            }

            if (!array_key_exists($month, $categoryMonthlyStats[$categoryId])) {
                $categoryMonthlyStats[$categoryId][$month] = 0;
            }

            $categoryMonthlyStats[$categoryId][$month] += $amount;
        }
        // convert if necessary
        if ($currency !== null) {
            foreach ($categoryMonthlyStats as $catId => $cStat) {
                foreach ($cStat as $cid => $val) {
                    $categoryMonthlyStats[$catId][$cid] = ($val === 0.0) ? 0 : $val / $currency['exchange_rate'];
                }
            }
        }

        return [
            'stats' => $statistics,
            'monthlyStats' => $monthlyStats,
            'categoryStats' => $categoryStats,
            'categoryMonthlyStats' => $categoryMonthlyStats,
            'categoryMemberStats' => $categoryMemberStats,
            'memberIds' => array_keys($membersToDisplay),
            'allMemberIds' => $allMembersIds,
            'membersPaidFor' => $membersPaidFor,
        ];
    }

    public function addBill($projectid, $date, $what, $payer, $payed_for,
                            $amount, $repeat, $paymentmode=null, $categoryid=null,
                            $repeatallactive=0, $repeatuntil=null, $timestamp=null,
                            $comment=null) {
        if ($repeat === null || $repeat === '' || strlen($repeat) !== 1) {
            return ['repeat' => $this->trans->t('Invalid value')];
        }
        if ($repeatallactive === null || ($repeatallactive !== '' && !is_numeric($repeatallactive))) {
            return ['repeatallactive' => $this->trans->t('Invalid value')];
        }
        if ($repeatallactive !== null && $repeatallactive === '') {
            $repeatallactive = 0;
        }
        if ($repeatuntil !== null && $repeatuntil === '') {
            $repeatuntil = null;
        }
        // priority to timestamp (moneybuster might send both for a moment)
        if ($timestamp === null || !is_numeric($timestamp)) {
            if ($date === null || $date === '') {
                return ['message' => $this->trans->t('Timestamp (or date) field is required')];
            }
            else {
                $dateTs = strtotime($date);
                if ($dateTs === false) {
                    return ['date' => $this->trans->t('Invalid date')];
                }
            }
        }
        else {
            $dateTs = intval($timestamp);
        }
        if ($what === null || $what === '') {
            return ['what' => $this->trans->t('This field is invalid')];
        }
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return ['amount' => $this->trans->t('This field is required')];
        }
        if ($payer === null || $payer === '' || !is_numeric($payer)) {
            return ['payer' => $this->trans->t('This field is required')];
        }
        if ($this->getMemberById($projectid, $payer) === null) {
            return ['payer' => $this->trans->t('Not a valid choice')];
        }
        // check owers
        $owerIds = explode(',', $payed_for);
        if ($payed_for === null || $payed_for === '' || count($owerIds) === 0) {
            return ['payed_for' => $this->trans->t('Invalid value')];
        }
        foreach ($owerIds as $owerId) {
            if (!is_numeric($owerId)) {
                return ['payed_for' => $this->trans->t('Invalid value')];
            }
            if ($this->getMemberById($projectid, $owerId) === null) {
                return ['payed_for' => $this->trans->t('Not a valid choice')];
            }
        }

        // last modification timestamp is now
        $ts = (new \DateTime())->getTimestamp();

        // do it already !
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->insert('cospend_bills')
            ->values([
                'projectid' => $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR),
                'what' => $qb->createNamedParameter($what, IQueryBuilder::PARAM_STR),
                'comment' => $qb->createNamedParameter($comment, IQueryBuilder::PARAM_STR),
                'timestamp' => $qb->createNamedParameter($dateTs, IQueryBuilder::PARAM_INT),
                'amount' => $qb->createNamedParameter($amount, IQueryBuilder::PARAM_STR),
                'payerid' => $qb->createNamedParameter($payer, IQueryBuilder::PARAM_INT),
                'repeat' => $qb->createNamedParameter($repeat, IQueryBuilder::PARAM_STR),
                'repeatallactive' => $qb->createNamedParameter($repeatallactive, IQueryBuilder::PARAM_INT),
                'repeatuntil' => $qb->createNamedParameter($repeatuntil, IQueryBuilder::PARAM_STR),
                'categoryid' => $qb->createNamedParameter($categoryid, IQueryBuilder::PARAM_INT),
                'paymentmode' => $qb->createNamedParameter($paymentmode, IQueryBuilder::PARAM_STR),
                'lastchanged' => $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT)
            ]);
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();

        $insertedBillId = $qb->getLastInsertId();

        // insert bill owers
        foreach ($owerIds as $owerId) {
            $qb->insert('cospend_bill_owers')
                ->values([
                    'billid' => $qb->createNamedParameter($insertedBillId, IQueryBuilder::PARAM_INT),
                    'memberid' => $qb->createNamedParameter($owerId, IQueryBuilder::PARAM_INT)
                ]);
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();
        }

        $this->updateProjectLastChanged($projectid, $ts);

        return $insertedBillId;
    }

    public function deleteBill($projectid, $billid) {
        $project = $this->getProjectInfo($projectid);
        $deletionDisabled = $project['deletion_disabled'];
        if ($deletionDisabled) {
            return ['message' => 'Forbidden'];
        }
        $billToDelete = $this->getBill($projectid, $billid);
        if ($billToDelete !== null) {
            $this->deleteBillOwersOfBill($billid);

            $qb = $this->dbconnection->getQueryBuilder();
            $qb->delete('cospend_bills')
               ->where(
                   $qb->expr()->eq('id', $qb->createNamedParameter($billid, IQueryBuilder::PARAM_INT))
               )
               ->andWhere(
                   $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
               );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            $ts = (new \DateTime())->getTimestamp();
            $this->updateProjectLastChanged($projectid, $ts);

            return 'OK';
        }
        else {
            return ['message' => $this->trans->t('Not Found')];
        }
    }

    private function getMemberById($projectId, $memberId) {
        $member = null;

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'userid', 'name', 'weight', 'color', 'activated')
           ->from('cospend_members', 'm')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('id', $qb->createNamedParameter($memberId, IQueryBuilder::PARAM_INT))
           );
        $req = $qb->execute();

        while ($row = $req->fetch()) {
            $dbMemberId = intval($row['id']);
            $dbWeight = floatval($row['weight']);
            $dbUserid = $row['userid'];
            $dbName = $row['name'];
            $dbActivated = intval($row['activated']);
            $dbColor = $row['color'];
            if ($dbColor === null) {
                $av = $this->avatarManager->getGuestAvatar($dbName);
                $dbColor = $av->avatarBackgroundColor($dbName);
            }
            else {
                $dbColor = $this->hexToRgb($dbColor);
            }

            $member = [
                    'activated' => ($dbActivated === 1),
                    'userid' => $dbUserid,
                    'name' => $dbName,
                    'id' => $dbMemberId,
                    'weight' => $dbWeight,
                    'color' => $dbColor
            ];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $member;
    }

    public function getProjectById($projectId) {
        $project = null;

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'userid', 'name', 'email', 'password', 'currencyname', 'autoexport', 'guestaccesslevel', 'lastchanged')
           ->from('cospend_projects', 'p')
           ->where(
               $qb->expr()->eq('id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();

        while ($row = $req->fetch()){
            $dbId = $row['id'];
            $dbPassword = $row['password'];
            $dbName = $row['name'];
            $dbUserId = $row['userid'];
            $dbEmail = $row['email'];
            $dbCurrencyName = $row['currencyname'];
            $dbAutoexport = $row['autoexport'];
            $dbLastchanged = intval($row['lastchanged']);
            $dbGuestAccessLevel = intval($row['guestaccesslevel']);
            $project = [
                'id' => $dbId,
                'name' => $dbName,
                'userid' => $dbUserId,
                'password' => $dbPassword,
                'email' => $dbEmail,
                'lastchanged' => $dbLastchanged,
                'currencyname' => $dbCurrencyName,
                'autoexport' => $dbAutoexport,
                'guestaccesslevel' => $dbGuestAccessLevel
            ];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $project;
    }

    public function getBill($projectId, $billId) {
        $bill = null;
        // get bill owers
        $billOwers = [];
        $billOwerIds = [];

        $qb = $this->dbconnection->getQueryBuilder();

        $qb->select('memberid', 'm.name', 'm.weight', 'm.activated')
           ->from('cospend_bill_owers', 'bo')
           ->innerJoin('bo', 'cospend_members', 'm', $qb->expr()->eq('bo.memberid', 'm.id'))
           ->where(
               $qb->expr()->eq('bo.billid', $qb->createNamedParameter($billId, IQueryBuilder::PARAM_INT))
           );
        $req = $qb->execute();

        while ($row = $req->fetch()){
            $dbWeight = floatval($row['weight']);
            $dbName = $row['name'];
            $dbActivated = (intval($row['activated']) === 1);
            $dbOwerId= intval($row['memberid']);
            array_push($billOwers, [
                'id' => $dbOwerId,
                'weight' => $dbWeight,
                'name' => $dbName,
                'activated' => $dbActivated
            ]);
            array_push($billOwerIds, $dbOwerId);
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        // get the bill
        $qb->select('id', 'what', 'comment', 'timestamp', 'amount', 'payerid', 'repeat',
                    'repeatallactive', 'paymentmode', 'categoryid', 'repeatuntil')
           ->from('cospend_bills', 'b')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('id', $qb->createNamedParameter($billId, IQueryBuilder::PARAM_INT))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()){
            $dbBillId = intval($row['id']);
            $dbAmount = floatval($row['amount']);
            $dbWhat = $row['what'];
            $dbComment = $row['comment'];
            $dbTimestamp = $row['timestamp'];
            $dbDate = \DateTime::createFromFormat('U', $dbTimestamp);
            $dbRepeat = $row['repeat'];
            $dbRepeatAllActive = $row['repeatallactive'];
            $dbRepeatUntil = $row['repeatuntil'];
            $dbPayerId = intval($row['payerid']);
            $dbPaymentMode = $row['paymentmode'];
            $dbCategoryId = intval($row['categoryid']);
            $bill = [
                'id' => $dbBillId,
                'amount' => $dbAmount,
                'what' => $dbWhat,
                'comment' => $dbComment,
                'date' => $dbDate->format('Y-m-d'),
                'timestamp' => $dbTimestamp,
                'payer_id' => $dbPayerId,
                'owers' => $billOwers,
                'owerIds' => $billOwerIds,
                'repeat' => $dbRepeat,
                'repeatallactive' => $dbRepeatAllActive,
                'repeatuntil' => $dbRepeatUntil,
                'paymentmode' => $dbPaymentMode,
                'categoryid' => $dbCategoryId
            ];
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $bill;
    }

    private function deleteBillOwersOfBill($billid) {
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->delete('cospend_bill_owers')
           ->where(
               $qb->expr()->eq('billid', $qb->createNamedParameter($billid, IQueryBuilder::PARAM_INT))
           );
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();
    }

    public function autoSettlement(string $projectid, ?int $centeredOn = null, int $precision = 2, ?int $maxTimestamp = null) {
        $settlement = $this->getProjectSettlement($projectid, $centeredOn, $maxTimestamp);
        $transactions = $settlement['transactions'];
        if (!is_array($transactions)) {
            return ['message' => $this->trans->t('Error when getting project settlement transactions')];
        }

        $members = $this->getMembers($projectid);
        $memberIdToName = [];
        foreach ($members as $member) {
            $memberIdToName[$member['id']] = $member['name'];
        }

        if ($maxTimestamp) {
            $ts = $maxTimestamp - 1;
        } else {
            $ts = (new \DateTime())->getTimestamp();
        }

        foreach ($transactions as $transaction) {
            $fromId = $transaction['from'];
            $toId = $transaction['to'];
            $amount = round(floatval($transaction['amount']), $precision);
            $billTitle = $memberIdToName[$fromId].' → '.$memberIdToName[$toId];
            $addBillResult = $this->addBill($projectid, null, $billTitle, $fromId, $toId, $amount, 'n', 'n', CAT_REIMBURSEMENT, 0, null, $ts);
            if (!is_numeric($addBillResult)) {
                return ['message' => $this->trans->t('Error when adding a bill')];
            }
        }
        return 'OK';
    }

    public function getProjectSettlement(string $projectId, ?int $centeredOn = null, ?int $maxTimestamp = null): array {
        $balances = $this->getBalance($projectId, $maxTimestamp);
        if ($centeredOn === null) {
            $transactions = $this->settle($balances);
        } else {
            $transactions = $this->centeredSettle($balances, $centeredOn);
        }
        return [
            'transactions' => $transactions,
            'balances' => $balances,
        ];
    }

    private function centeredSettle($balances, $centeredOn) {
        $transactions = [];
        foreach ($balances as $memberId => $balance) {
            if ($memberId !== $centeredOn) {
                if ($balance > 0.0) {
                    array_push($transactions, [
                        'from' => $centeredOn,
                        'to' => $memberId,
                        'amount' => $balance
                    ]);
                } else if ($balance < 0.0) {
                    array_push($transactions, [
                        'from' => $memberId,
                        'to' => $centeredOn,
                        'amount' => -$balance
                    ]);
                }
            }
        }
        return $transactions;
    }

    private function settle($balances) {
        $debitersCrediters = $this->orderBalance($balances);
        $debiters = $debitersCrediters[0];
        $crediters = $debitersCrediters[1];
        return $this->reduceBalance($crediters, $debiters);
    }

    private function orderBalance($balances) {
        $crediters = [];
        $debiters = [];
        foreach ($balances as $id => $balance) {
            if ($balance > 0.0) {
                array_push($crediters, [$id, $balance]);
            }
            else if ($balance < 0.0) {
                array_push($debiters, [$id, $balance]);
            }
        }

        return [$debiters, $crediters];
    }

    private function reduceBalance($crediters, $debiters, $results=null) {
        if (count($crediters) === 0 || count($debiters) === 0) {
            return $results;
        }

        if ($results === null) {
            $results = [];
        }

        $crediters = $this->sortCreditersDebiters($crediters);
        $debiters = $this->sortCreditersDebiters($debiters, true);

        $deb = array_pop($debiters);
        $debiter = $deb[0];
        $debiterBalance = $deb[1];

        $cred = array_pop($crediters);
        $crediter = $cred[0];
        $crediterBalance = $cred[1];

        if (abs($debiterBalance) > abs($crediterBalance)) {
            $amount = abs($crediterBalance);
        }
        else {
            $amount = abs($debiterBalance);
        }

        $newResults = $results;
        array_push($newResults, ['to' => $crediter, 'amount' => $amount, 'from' => $debiter]);

        $newDebiterBalance = $debiterBalance + $amount;
        if ($newDebiterBalance < 0.0) {
            array_push($debiters, [$debiter, $newDebiterBalance]);
            $debiters = $this->sortCreditersDebiters($debiters, true);
        }

        $newCrediterBalance = $crediterBalance - $amount;
        if ($newCrediterBalance > 0.0) {
            array_push($crediters, [$crediter, $newCrediterBalance]);
            $crediters = $this->sortCreditersDebiters($crediters);
        }

        return $this->reduceBalance($crediters, $debiters, $newResults);
    }

    private function sortCreditersDebiters($arr, $reverse=false) {
        $res = [];
        if ($reverse) {
            foreach ($arr as $elem) {
                $i = 0;
                while ($i < count($res) && $elem[1] < $res[$i][1]) {
                    $i++;
                }
                array_splice($res, $i, 0, [$elem]);
            }
        }
        else {
            foreach ($arr as $elem) {
                $i = 0;
                while ($i < count($res) && $elem[1] >= $res[$i][1]) {
                    $i++;
                }
                array_splice($res, $i, 0, [$elem]);
            }
        }
        return $res;
    }

    public function editMember($projectid, $memberid, $name, $userid, $weight, $activated, $color=null): array {
        if ($name !== null && $name !== '') {
            $member = $this->getMemberById($projectid, $memberid);
            if ($member !== null) {
                $qb = $this->dbconnection->getQueryBuilder();
                // delete member if it has no bill and we are disabling it
                if (count($this->getBillsOfMember($projectid, $memberid)) === 0
                    && $member['activated']
                    && ($activated === 'false' || $activated === false)
                ) {
                    $qb->delete('cospend_members')
                        ->where(
                            $qb->expr()->eq('id', $qb->createNamedParameter($memberid, IQueryBuilder::PARAM_INT))
                        );
                    $req = $qb->execute();
                    $qb = $qb->resetQueryParts();
                    return [];
                }
                // get existing member with this name
                $memberWithSameName = $this->getMemberByName($projectid, $name);
                if (strpos($name, '/') !== false) {
                    return ['name' => $this->trans->t('Invalid member name')];
                } elseif ($memberWithSameName && $memberWithSameName['id'] !== intval($memberid)) {
                    return ['name' => $this->trans->t('Name already exists')];
                }
                $qb->update('cospend_members');
                if ($weight !== null && $weight !== '') {
                    if (is_numeric($weight) && floatval($weight) > 0.0) {
                        $newWeight = floatval($weight);
                        $qb->set('weight', $qb->createNamedParameter($newWeight, IQueryBuilder::PARAM_STR));
                    }
                    else {
                        return ['weight' => $this->trans->t('Not a valid decimal value')];
                    }
                }
                if ($activated !== null && $activated !== '' && ($activated === 'true' || $activated === 'false')) {
                    $qb->set('activated', $qb->createNamedParameter(($activated === 'true' ? 1 : 0), IQueryBuilder::PARAM_INT));
                }

                $ts = (new \DateTime())->getTimestamp();
                $qb->set('lastchanged', $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT));

                $qb->set('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR));
                if ($color !== null) {
                    if ($color === '') {
                        $qb->set('color', $qb->createNamedParameter(null, IQueryBuilder::PARAM_STR));
                    }
                    else {
                        $qb->set('color', $qb->createNamedParameter($color, IQueryBuilder::PARAM_STR));
                    }
                }
                if ($userid !== null) {
                    if ($userid === '') {
                        $qb->set('userid', $qb->createNamedParameter(null, IQueryBuilder::PARAM_STR));
                    } else {
                        $qb->set('userid', $qb->createNamedParameter($userid, IQueryBuilder::PARAM_STR));
                    }
                }
                $qb->where(
                    $qb->expr()->eq('id', $qb->createNamedParameter($memberid, IQueryBuilder::PARAM_INT))
                )
                ->andWhere(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
                $req = $qb->execute();
                $qb = $qb->resetQueryParts();

                $editedMember = $this->getMemberById($projectid, $memberid);

                return $editedMember;
            }
            else {
                return ['name' => $this->trans->t('This project have no such member')];
            }
        }
        else {
            return ['name' => $this->trans->t('This field is required')];
        }
    }

    public function editProject(string $projectid, string $name, ?string $contact_email = null, ?string $password = null,
                                ?string $autoexport = null, ?string $currencyname = null, ?bool $deletion_disabled = null,
                                ?string $categorysort = null) {
        if ($name === null || $name === '') {
            return ['name' => [$this->trans->t('Name field is required')]];
        }

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->update('cospend_projects');

        $emailSql = '';
        if ($contact_email !== null && $contact_email !== '') {
            if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
                $qb->set('email', $qb->createNamedParameter($contact_email, IQueryBuilder::PARAM_STR));
            } else {
                return ['contact_email' => [$this->trans->t('Invalid email address')]];
            }
        }
        if ($password !== null && $password !== '') {
            $dbPassword = password_hash($password, PASSWORD_DEFAULT);
            $qb->set('password', $qb->createNamedParameter($dbPassword, IQueryBuilder::PARAM_STR));
        }
        if ($autoexport !== null && $autoexport !== '') {
            $qb->set('autoexport', $qb->createNamedParameter($autoexport, IQueryBuilder::PARAM_STR));
        }
        if ($categorysort !== null && $categorysort !== '') {
            $qb->set('categorysort', $qb->createNamedParameter($categorysort, IQueryBuilder::PARAM_STR));
        }
        if ($deletion_disabled !== null) {
            $qb->set('deletiondisabled', $qb->createNamedParameter($deletion_disabled ? 1 : 0, IQueryBuilder::PARAM_INT));
        }
        if ($currencyname !== null) {
            if ($currencyname === '') {
                $qb->set('currencyname', $qb->createNamedParameter(null, IQueryBuilder::PARAM_STR));
            } else {
                $qb->set('currencyname', $qb->createNamedParameter($currencyname, IQueryBuilder::PARAM_STR));
            }
        }
        if ($this->getProjectById($projectid) !== null) {
            $ts = (new \DateTime())->getTimestamp();
            $qb->set('lastchanged', $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT));
            $qb->set('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR));
            $qb->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
            );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            return 'UPDATED';
        } else {
            return ['message' => $this->trans->t('There is no such project')];
        }
    }

    public function addMember($projectid, $name, $weight, $active=1, $color=null, $userid=null) {
        if ($name !== null && $name !== '') {
            if ($this->getMemberByName($projectid, $name) === null && $this->getMemberByUserid($projectid, $userid) === null) {
                if (strpos($name, '/') !== false) {
                    return $this->trans->t('Invalid member name');
                }
                $weightToInsert = 1;
                if ($weight !== null && $weight !== '') {
                    if (is_numeric($weight) && floatval($weight) > 0.0) {
                        $weightToInsert = floatval($weight);
                    }
                    else {
                        return $this->trans->t('Weight is not a valid decimal value');
                    }
                }
                if ($active === null || !is_numeric($active)) {
                    return $this->trans->t('Active is not a valid integer value');
                }

                $ts = (new \DateTime())->getTimestamp();

                $qb = $this->dbconnection->getQueryBuilder();
                $qb->insert('cospend_members')
                    ->values([
                        'projectid' => $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR),
                        'userid' => $qb->createNamedParameter($userid, IQueryBuilder::PARAM_STR),
                        'name' => $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR),
                        'weight' => $qb->createNamedParameter($weightToInsert, IQueryBuilder::PARAM_STR),
                        'activated' => $qb->createNamedParameter($active, IQueryBuilder::PARAM_INT),
                        'color' => $qb->createNamedParameter($color, IQueryBuilder::PARAM_STR),
                        'lastchanged' => $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT)
                    ]);
                $req = $qb->execute();
                $qb = $qb->resetQueryParts();

                $insertedMember = $this->getMemberByName($projectid, $name);

                return $insertedMember;
            }
            else {
                return $this->trans->t('This project already has this member');
            }
        }
        else {
            return $this->trans->t('Name field is required');
        }
    }

    public function getNbBills(string $projectId): int {
        $nb = 0;
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'count_bills')
           ->from('cospend_bills', 'bi')
           ->where(
               $qb->expr()->eq('bi.projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()) {
            $nb = (int) $row['count_bills'];
        }
        return $nb;
    }

    public function getBillsRestricted(string $projectId, ?int $tsMin = null, ?int $tsMax = null, ?string $paymentMode = null, ?int $category = null,
                              ?float $amountMin = null, ?float $amountMax = null, ?int $lastchanged = null, ?int $limit = null,
                              bool $reverse = false, int $offset = 0) {
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'what', 'comment', 'timestamp', 'amount', 'payerid', 'repeat',
                    'paymentmode', 'categoryid', 'lastchanged', 'repeatallactive', 'repeatuntil')
           ->from('cospend_bills', 'bi')
           ->where(
               $qb->expr()->eq('bi.projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           );
        // take bills that have changed after $lastchanged
        if ($lastchanged !== null && is_numeric($lastchanged)) {
            $qb->andWhere(
                $qb->expr()->gt('bi.lastchanged', $qb->createNamedParameter(intval($lastchanged), IQueryBuilder::PARAM_INT))
            );
        }
        if (is_numeric($tsMin)) {
            $qb->andWhere(
                $qb->expr()->gte('timestamp', $qb->createNamedParameter($tsMin, IQueryBuilder::PARAM_INT))
            );
        }
        if (is_numeric($tsMax)) {
            $qb->andWhere(
                $qb->expr()->lte('timestamp', $qb->createNamedParameter($tsMax, IQueryBuilder::PARAM_INT))
            );
        }
        if ($paymentMode !== null && $paymentMode !== '' && $paymentMode !== 'n') {
            $qb->andWhere(
                $qb->expr()->eq('paymentmode', $qb->createNamedParameter($paymentMode, IQueryBuilder::PARAM_STR))
            );
        }
        if ($category !== null && $category !== '' && intval($category) !== 0) {
            if (intval($category) === -100) {
                $or = $qb->expr()->orx();
                $or->add($qb->expr()->isNull('categoryid'));
                $or->add($qb->expr()->neq('categoryid', $qb->createNamedParameter(CAT_REIMBURSEMENT, IQueryBuilder::PARAM_INT)));
                $qb->andWhere($or);
            }
            else {
                $qb->andWhere(
                    $qb->expr()->eq('categoryid', $qb->createNamedParameter(intval($category), IQueryBuilder::PARAM_INT))
                );
            }
        }
        if ($amountMin !== null && is_numeric($amountMin)) {
           $qb->andWhere(
               $qb->expr()->gte('amount', $qb->createNamedParameter(floatval($amountMin), IQueryBuilder::PARAM_STR))
           );
        }
        if ($amountMax !== null && is_numeric($amountMax)) {
           $qb->andWhere(
               $qb->expr()->lte('amount', $qb->createNamedParameter(floatval($amountMax), IQueryBuilder::PARAM_STR))
           );
        }
        if ($reverse) {
            $qb->orderBy('timestamp', 'DESC');
        } else {
            $qb->orderBy('timestamp', 'ASC');
        }
        if ($limit) {
            $qb->setMaxResults($limit);
        }
        if ($offset) {
            $qb->setFirstResult($offset);
        }
        $req = $qb->execute();

        $bills = [];
        while ($row = $req->fetch()){
            $dbBillId = intval($row['id']);
            $dbAmount = floatval($row['amount']);
            $dbWhat = $row['what'];
            $dbComment = $row['comment'];
            $dbTimestamp = $row['timestamp'];
            $dbDate = \DateTime::createFromFormat('U', $dbTimestamp);
            $dbRepeat = $row['repeat'];
            $dbPayerId = intval($row['payerid']);
            $dbPaymentMode = $row['paymentmode'];
            $dbCategoryId = intval($row['categoryid']);
            $dbLastchanged = intval($row['lastchanged']);
            $dbRepeatAllActive = intval($row['repeatallactive']);
            $dbRepeatUntil = $row['repeatuntil'];
            $bills[] = [
                'id' => $dbBillId,
                'amount' => $dbAmount,
                'what' => $dbWhat,
                'comment' => $dbComment,
                'timestamp' => $dbTimestamp,
                'date' => $dbDate->format('Y-m-d'),
                'payer_id' => $dbPayerId,
                'owers' => [],
                'owerIds' => [],
                'repeat' => $dbRepeat,
                'paymentmode' => $dbPaymentMode,
                'categoryid' => $dbCategoryId,
                'lastchanged' => $dbLastchanged,
                'repeatallactive' => $dbRepeatAllActive,
                'repeatuntil' => $dbRepeatUntil,
            ];
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        // get owers
        foreach ($bills as $i => $bill) {
            $billId = $bill['id'];
            $billOwers = [];
            $billOwerIds = [];

            $qb->select('memberid', 'm.name', 'm.weight', 'm.activated')
               ->from('cospend_bill_owers', 'bo')
               ->innerJoin('bo', 'cospend_members', 'm', $qb->expr()->eq('bo.memberid', 'm.id'))
               ->where(
                   $qb->expr()->eq('bo.billid', $qb->createNamedParameter($billId, IQueryBuilder::PARAM_INT))
               );
            $qb->setFirstResult(0);
            $req = $qb->execute();
            while ($row = $req->fetch()){
                $dbWeight = floatval($row['weight']);
                $dbName = $row['name'];
                $dbActivated = (intval($row['activated']) === 1);
                $dbOwerId= intval($row['memberid']);
                $billOwers[] = [
                    'id' => $dbOwerId,
                    'weight' => $dbWeight,
                    'name' => $dbName,
                    'activated' => $dbActivated
                ];
                $billOwerIds[] = $dbOwerId;
            }
            $req->closeCursor();
            $qb = $qb->resetQueryParts();
            $bills[$i]['owers'] = $billOwers;
            $bills[$i]['owerIds'] = $billOwerIds;
        }

        return $bills;
    }

    public function getBills(string $projectId, ?int $tsMin = null, ?int $tsMax = null, ?string $paymentMode = null, ?int $category = null,
                              ?float $amountMin = null, ?float $amountMax = null, ?int $lastchanged = null, ?int $limit = null,
                              bool $reverse = false) {
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('bi.id', 'what', 'comment', 'timestamp', 'amount', 'payerid', 'repeat',
                    'paymentmode', 'categoryid', 'bi.lastchanged', 'repeatallactive', 'repeatuntil',
                    'memberid', 'm.name', 'm.weight', 'm.activated')
           ->from('cospend_bill_owers', 'bo')
           ->innerJoin('bo', 'cospend_bills', 'bi', $qb->expr()->eq('bo.billid', 'bi.id'))
           ->innerJoin('bo', 'cospend_members', 'm', $qb->expr()->eq('bo.memberid', 'm.id'))
           ->where(
               $qb->expr()->eq('bi.projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           );
        // take bills that have changed after $lastchanged
        if ($lastchanged !== null && is_numeric($lastchanged)) {
            $qb->andWhere(
                $qb->expr()->gt('bi.lastchanged', $qb->createNamedParameter(intval($lastchanged), IQueryBuilder::PARAM_INT))
            );
        }
        if (is_numeric($tsMin)) {
            $qb->andWhere(
                $qb->expr()->gte('timestamp', $qb->createNamedParameter($tsMin, IQueryBuilder::PARAM_INT))
            );
        }
        if (is_numeric($tsMax)) {
            $qb->andWhere(
                $qb->expr()->lte('timestamp', $qb->createNamedParameter($tsMax, IQueryBuilder::PARAM_INT))
            );
        }
        if ($paymentMode !== null && $paymentMode !== '' && $paymentMode !== 'n') {
            $qb->andWhere(
                $qb->expr()->eq('paymentmode', $qb->createNamedParameter($paymentMode, IQueryBuilder::PARAM_STR))
            );
        }
        if ($category !== null && $category !== '' && intval($category) !== 0) {
            if (intval($category) === -100) {
                $or = $qb->expr()->orx();
                $or->add($qb->expr()->isNull('categoryid'));
                $or->add($qb->expr()->neq('categoryid', $qb->createNamedParameter(CAT_REIMBURSEMENT, IQueryBuilder::PARAM_INT)));
                $qb->andWhere($or);
            }
            else {
                $qb->andWhere(
                    $qb->expr()->eq('categoryid', $qb->createNamedParameter(intval($category), IQueryBuilder::PARAM_INT))
                );
            }
        }
        if ($amountMin !== null && is_numeric($amountMin)) {
           $qb->andWhere(
               $qb->expr()->gte('amount', $qb->createNamedParameter(floatval($amountMin), IQueryBuilder::PARAM_STR))
           );
        }
        if ($amountMax !== null && is_numeric($amountMax)) {
           $qb->andWhere(
               $qb->expr()->lte('amount', $qb->createNamedParameter(floatval($amountMax), IQueryBuilder::PARAM_STR))
           );
        }
        if ($reverse) {
            $qb->orderBy('timestamp', 'DESC');
        } else {
            $qb->orderBy('timestamp', 'ASC');
        }
        if ($limit) {
            $qb->setMaxResults($limit);
        }
        $req = $qb->execute();

        // bills by id
        $billDict = [];
        // ordered list of bill ids
        $orderedBillIds = [];
        while ($row = $req->fetch()){
            $dbBillId = intval($row['id']);
            // if first time we see the bill : add it to bill list
            if (!array_key_exists($dbBillId, $billDict)) {
                $dbAmount = floatval($row['amount']);
                $dbWhat = $row['what'];
                $dbComment = $row['comment'];
                $dbTimestamp = $row['timestamp'];
                $dbDate = \DateTime::createFromFormat('U', $dbTimestamp);
                $dbRepeat = $row['repeat'];
                $dbPayerId = intval($row['payerid']);
                $dbPaymentMode = $row['paymentmode'];
                $dbCategoryId = intval($row['categoryid']);
                $dbLastchanged = intval($row['lastchanged']);
                $dbRepeatAllActive = intval($row['repeatallactive']);
                $dbRepeatUntil = $row['repeatuntil'];
                $billDict[$dbBillId] = [
                    'id' => $dbBillId,
                    'amount' => $dbAmount,
                    'what' => $dbWhat,
                    'comment' => $dbComment,
                    'timestamp' => $dbTimestamp,
                    'date' => $dbDate->format('Y-m-d'),
                    'payer_id' => $dbPayerId,
                    'owers' => [],
                    'owerIds' => [],
                    'repeat' => $dbRepeat,
                    'paymentmode' => $dbPaymentMode,
                    'categoryid' => $dbCategoryId,
                    'lastchanged' => $dbLastchanged,
                    'repeatallactive' => $dbRepeatAllActive,
                    'repeatuntil' => $dbRepeatUntil
                ];
                // keep order of bills
                array_push($orderedBillIds, $dbBillId);
            }
            // anyway add an ower
            $dbWeight = floatval($row['weight']);
            $dbName = $row['name'];
            $dbActivated = (intval($row['activated']) === 1);
            $dbOwerId= intval($row['memberid']);
            array_push($billDict[$dbBillId]['owers'], [
                'id' => $dbOwerId,
                'weight' => $dbWeight,
                'name' => $dbName,
                'activated' => $dbActivated
            ]);
            array_push($billDict[$dbBillId]['owerIds'], $dbOwerId);
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        $resultBills = [];
        foreach ($orderedBillIds as $bid) {
            array_push($resultBills, $billDict[$bid]);
        }

        return $resultBills;
    }

    public function getAllBillIds($projectId) {
        $billIds = [];
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id')
           ->from('cospend_bills', 'b')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();

        while ($row = $req->fetch()){
            array_push($billIds, $row['id']);
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $billIds;
    }

    public function getMembers($projectId, $order=null, $lastchanged=null) {
        $members = [];

        $sqlOrder = 'name';
        if ($order !== null) {
            if ($order === 'lowername') {
                $sqlOrder = 'name';
            }
            else {
                $sqlOrder = $order;
            }
        }

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'userid', 'name', 'weight', 'color', 'activated', 'lastchanged')
           ->from('cospend_members', 'm')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           );
        if ($lastchanged !== null && is_numeric($lastchanged)) {
           $qb->andWhere(
               $qb->expr()->gt('lastchanged', $qb->createNamedParameter($lastchanged, IQueryBuilder::PARAM_INT))
           );
        }
        $qb->orderBy($sqlOrder, 'ASC');
        $req = $qb->execute();

        if ($order === 'lowername') {
            while ($row = $req->fetch()){
                $dbMemberId = intval($row['id']);
                $dbWeight = floatval($row['weight']);
                $dbUserid = $row['userid'];
                $dbName = $row['name'];
                $dbActivated = intval($row['activated']);
                $dbLastchanged = intval($row['lastchanged']);
                $dbColor = $row['color'];
                if ($dbColor === null) {
                    $av = $this->avatarManager->getGuestAvatar($dbName);
                    $dbColor = $av->avatarBackgroundColor($dbName);
                }
                else {
                    $dbColor = $this->hexToRgb($dbColor);
                }

                // find index to make sorted insert
                $ii = 0;
                while ($ii < count($members) && strcmp(strtolower($dbName), strtolower($members[$ii]['name'])) > 0) {
                    $ii++;
                }

                array_splice(
                    $members,
                    $ii,
                    0,
                    [[
                        'activated' => ($dbActivated === 1),
                        'userid' => $dbUserid,
                        'name' => $dbName,
                        'id' => $dbMemberId,
                        'weight' => $dbWeight,
                        'color' => $dbColor,
                        'lastchanged' => $dbLastchanged
                    ]]
                );
            }
        }
        else {
            while ($row = $req->fetch()){
                $dbMemberId = intval($row['id']);
                $dbWeight = floatval($row['weight']);
                $dbUserid = $row['userid'];
                $dbName = $row['name'];
                $dbActivated = intval($row['activated']);
                $dbLastchanged = intval($row['lastchanged']);
                $dbColor = $row['color'];
                if ($dbColor === null) {
                    $av = $this->avatarManager->getGuestAvatar($dbName);
                    $dbColor = $av->avatarBackgroundColor($dbName);
                }
                else {
                    $dbColor = $this->hexToRgb($dbColor);
                }

                array_push(
                    $members,
                    [
                        'activated' => ($dbActivated === 1),
                        'userid' => $dbUserid,
                        'name' => $dbName,
                        'id' => $dbMemberId,
                        'weight' => $dbWeight,
                        'color' => $dbColor,
                        'lastchanged' => $dbLastchanged
                    ]
                );
            }
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $members;
    }

    private function getBalance(string $projectId, ?int $maxTimestamp = null) {
        $membersWeight = [];
        $membersBalance = [];

        $members = $this->getMembers($projectId);
        foreach ($members as $member) {
            $memberId = $member['id'];
            $memberWeight = $member['weight'];
            $membersWeight[$memberId] = $memberWeight;
            $membersBalance[$memberId] = 0.0;
        }

        $bills = $this->getBills($projectId, null, $maxTimestamp);
        foreach ($bills as $bill) {
            $payerId = $bill['payer_id'];
            $amount = $bill['amount'];
            $owers = $bill['owers'];

            $membersBalance[$payerId] += $amount;

            $nbOwerShares = 0.0;
            foreach ($owers as $ower) {
                $owerWeight = $ower['weight'];
                if ($owerWeight === 0.0) {
                    $owerWeight = 1.0;
                }
                $nbOwerShares += $owerWeight;
            }
            foreach ($owers as $ower) {
                $owerWeight = $ower['weight'];
                if ($owerWeight === 0.0) {
                    $owerWeight = 1.0;
                }
                $owerId = $ower['id'];
                $spent = $amount / $nbOwerShares * $owerWeight;
                $membersBalance[$owerId] -= $spent;
            }
        }

        return $membersBalance;
    }

    private function isUserInCircle($userId, $circleId) {
        $circleDetails = null;
        try {
            $circleDetails = \OCA\Circles\Api\v1\Circles::detailsCircle($circleId);
        }
        catch (\OCA\Circles\Exceptions\CircleDoesNotExistException $e) {
        }
        if ($circleDetails) {
            // is the circle owner
            if ($circleDetails->getOwner()->getUserId() === $userId) {
                return true;
            }
            else {
                if ($circleDetails->getMembers() !== null) {
                    foreach ($circleDetails->getMembers() as $m) {
                        // is member of this circle
                        if ($m->getUserId() === $userId) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    public function getProjects(string $userId): array {
        $projectids = [];

        $qb = $this->dbconnection->getQueryBuilder();

        $qb->select('id')
           ->from('cospend_projects', 'p')
           ->where(
               $qb->expr()->eq('userid', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();

        while ($row = $req->fetch()){
            $dbProjectId = $row['id'];
            $projectids[] = $dbProjectId;
        }
        $req->closeCursor();

        $qb = $qb->resetQueryParts();

        // shared with user
        $qb->select('p.id')
           ->from('cospend_projects', 'p')
           ->innerJoin('p', 'cospend_shares', 's', $qb->expr()->eq('p.id', 's.projectid'))
           ->where(
               $qb->expr()->eq('s.userid', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('s.type', $qb->createNamedParameter('u', IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();

        while ($row = $req->fetch()){
            $dbProjectId = $row['id'];
            // avoid putting twice the same project
            // this can happen with a share loop
            if (!in_array($dbProjectId, $projectids)) {
                $projectids[] = $dbProjectId;
            }
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        // shared with one of the groups the user is member of
        $userO = $this->userManager->get($userId);

        // get group with which a project is shared
        $candidateGroupIds = [];
        $qb->select('userid')
           ->from('cospend_shares', 's')
           ->where(
               $qb->expr()->eq('type', $qb->createNamedParameter('g', IQueryBuilder::PARAM_STR))
           )
           ->groupBy('userid');
        $req = $qb->execute();
        while ($row = $req->fetch()){
            $groupId = $row['userid'];
            $candidateGroupIds[] = $groupId;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        // is the user member of these groups?
        foreach ($candidateGroupIds as $candidateGroupId) {
            $group = $this->groupManager->get($candidateGroupId);
            if ($group !== null && $group->inGroup($userO)) {
                // get projects shared with this group
                $qb->select('p.id')
                    ->from('cospend_projects', 'p')
                    ->innerJoin('p', 'cospend_shares', 's', $qb->expr()->eq('p.id', 's.projectid'))
                    ->where(
                        $qb->expr()->eq('s.userid', $qb->createNamedParameter($candidateGroupId, IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('s.type', $qb->createNamedParameter('g', IQueryBuilder::PARAM_STR))
                    );
                $req = $qb->execute();

                while ($row = $req->fetch()){
                    $dbProjectId = $row['id'];
                    // avoid putting twice the same project
                    // this can happen with a share loop
                    if (!in_array($dbProjectId, $projectids)) {
                        $projectids[] = $dbProjectId;
                    }
                }
                $req->closeCursor();
                $qb = $qb->resetQueryParts();
            }
        }

        $circlesEnabled = \OC::$server->getAppManager()->isEnabledForUser('circles');
        if ($circlesEnabled) {
            // get circles with which a project is shared
            $candidateCircleIds = [];
            $qb->select('userid')
            ->from('cospend_shares', 's')
            ->where(
                $qb->expr()->eq('type', $qb->createNamedParameter('c', IQueryBuilder::PARAM_STR))
            )
            ->groupBy('userid');
            $req = $qb->execute();
            while ($row = $req->fetch()){
                $circleId = $row['userid'];
                $candidateCircleIds[] = $circleId;
            }
            $req->closeCursor();
            $qb = $qb->resetQueryParts();

            // is the user member of these circles?
            foreach ($candidateCircleIds as $candidateCircleId) {
                if ($this->isUserInCircle($userId, $candidateCircleId)) {
                    // get projects shared with this circle
                    $qb->select('p.id')
                        ->from('cospend_projects', 'p')
                        ->innerJoin('p', 'cospend_shares', 's', $qb->expr()->eq('p.id', 's.projectid'))
                        ->where(
                            $qb->expr()->eq('s.userid', $qb->createNamedParameter($candidateCircleId, IQueryBuilder::PARAM_STR))
                        )
                        ->andWhere(
                            $qb->expr()->eq('s.type', $qb->createNamedParameter('c', IQueryBuilder::PARAM_STR))
                        );
                    $req = $qb->execute();

                    while ($row = $req->fetch()){
                        $dbProjectId = $row['id'];
                        // avoid putting twice the same project
                        // this can happen with a share loop or multiple shares
                        if (!in_array($dbProjectId, $projectids)) {
                            $projectids[] = $dbProjectId;
                        }
                    }
                    $req->closeCursor();
                    $qb = $qb->resetQueryParts();
                }
            }
        }

        // get the projects
        $projects = [];
        foreach ($projectids as $projectid) {
            $project = $this->getProjectInfo($projectid);
            $project['myaccesslevel'] = $this->getUserMaxAccessLevel($userId, $projectid);
            $projects[] = $project;
        }

        return $projects;
    }

    private function getCategories($projectid) {
        $categories = [];

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('name', 'id', 'encoded_icon', 'color', 'order')
           ->from('cospend_project_categories', 'c')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()){
            $dbName = $row['name'];
            $dbIcon = urldecode($row['encoded_icon']);
            $dbColor = $row['color'];
            $dbId = intval($row['id']);
            $dbOrder = intval($row['order']);
            $categories[$dbId] = [
                'name' => $dbName,
                'icon' => $dbIcon,
                'color' => $dbColor,
                'id' => $dbId,
                'order' => $dbOrder,
            ];
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $categories;
    }

    private function getCurrencies($projectid) {
        $currencies = [];

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('name', 'id', 'exchange_rate')
           ->from('cospend_currencies', 'c')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()){
            $dbName = $row['name'];
            $dbId = intval($row['id']);
            $dbExchangeRate = floatval($row['exchange_rate']);
            array_push($currencies, [
                'name' => $dbName,
                'exchange_rate' => $dbExchangeRate,
                'id' => $dbId
            ]);
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $currencies;
    }

    private function getUserShares($projectid) {
        $shares = [];

        $userIdToName = [];
        foreach($this->userManager->search('') as $u) {
            $userIdToName[$u->getUID()] = $u->getDisplayName();
        }

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('projectid', 'userid', 'id', 'accesslevel', 'manually_added')
           ->from('cospend_shares', 'sh')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('type', $qb->createNamedParameter('u', IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()){
            $dbuserId = $row['userid'];
            $dbprojectId = $row['projectid'];
            $dbId = $row['id'];
            $dbAccessLevel = intval($row['accesslevel']);
            $dbManuallyAdded = intval($row['manually_added']);
            if (array_key_exists($dbuserId, $userIdToName)) {
                array_push($shares, [
                    'userid' => $dbuserId,
                    'name' => $userIdToName[$dbuserId],
                    'id' => $dbId,
                    'accesslevel' => $dbAccessLevel,
                    'type' => 'u',
                    'manually_added' => $dbManuallyAdded === 1,
                ]);
            }
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $shares;
    }

    private function getPublicShares($projectid, $maxAccessLevel=null) {
        $shares = [];

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('projectid', 'userid', 'id', 'accesslevel')
           ->from('cospend_shares', 'sh')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('type', $qb->createNamedParameter('l', IQueryBuilder::PARAM_STR))
           );
        if (!is_null($maxAccessLevel)) {
           $qb->andWhere(
               $qb->expr()->lte('accesslevel', $qb->createNamedParameter($maxAccessLevel, IQueryBuilder::PARAM_INT))
           );
        }
        $req = $qb->execute();
        while ($row = $req->fetch()){
            $dbToken = $row['userid'];
            $dbprojectId = $row['projectid'];
            $dbId = $row['id'];
            $dbAccessLevel = intval($row['accesslevel']);
            array_push($shares, [
                'token' => $dbToken,
                'id' => $dbId,
                'accesslevel' => $dbAccessLevel,
                'type' => 'l'
            ]);
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $shares;
    }

    public function getProjectInfoFromShareToken($token) {
        $projectId = null;
        $accessLevel = null;

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('projectid', 'accesslevel')
           ->from('cospend_shares', 'sh')
           ->where(
               $qb->expr()->eq('userid', $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('type', $qb->createNamedParameter('l', IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()){
            $projectId = $row['projectid'];
            $accessLevel = intval($row['accesslevel']);
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return [
            'projectid' => $projectId,
            'accesslevel' => $accessLevel
        ];
    }

    private function getGroupShares($projectid) {
        $shares = [];

        $groupIdToName = [];
        foreach($this->groupManager->search('') as $g) {
            $groupIdToName[$g->getGID()] = $g->getDisplayName();
        }

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('projectid', 'userid', 'id', 'accesslevel')
           ->from('cospend_shares', 'sh')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('type', $qb->createNamedParameter('g', IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();
        while ($row = $req->fetch()){
            $dbGroupId = $row['userid'];
            $dbprojectId = $row['projectid'];
            $dbId = $row['id'];
            $dbAccessLevel = intval($row['accesslevel']);
            if (array_key_exists($dbGroupId, $groupIdToName)) {
                array_push($shares, [
                    'groupid' => $dbGroupId,
                    'name' => $groupIdToName[$dbGroupId],
                    'id' => $dbId,
                    'accesslevel' => $dbAccessLevel,
                    'type' => 'g'
                ]);
            }
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $shares;
    }

    private function getCircleShares($projectid) {
        $shares = [];

        $circlesEnabled = \OC::$server->getAppManager()->isEnabledForUser('circles');
        if ($circlesEnabled) {
            try {
                $circleIdToName = [];
                $cs = \OCA\Circles\Api\v1\Circles::listCircles(\OCA\Circles\Model\Circle::CIRCLES_ALL, '', 0);
                foreach ($cs as $c) {
                    $circleUniqueId = $c->getUniqueId();
                    $circleName = $c->getName();
                    $circleIdToName[$circleUniqueId] = $circleName;
                }

                $qb = $this->dbconnection->getQueryBuilder();
                $qb->select('projectid', 'userid', 'id', 'accesslevel')
                ->from('cospend_shares', 'sh')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                )
                ->andWhere(
                    $qb->expr()->eq('type', $qb->createNamedParameter('c', IQueryBuilder::PARAM_STR))
                );
                $req = $qb->execute();
                while ($row = $req->fetch()){
                    $dbCircleId = $row['userid'];
                    $dbprojectId = $row['projectid'];
                    $dbId = $row['id'];
                    $dbAccessLevel = intval($row['accesslevel']);
                    if (array_key_exists($dbCircleId, $circleIdToName)) {
                        array_push($shares, [
                            'circleid' => $dbCircleId,
                            'name' => $circleIdToName[$dbCircleId],
                            'id' => $dbId,
                            'accesslevel' => $dbAccessLevel,
                            'type' => 'c'
                        ]);
                    }
                }
                $req->closeCursor();
                $qb = $qb->resetQueryParts();
            } catch (\Throwable $e) {
                return [];
            }
        }
        return $shares;
    }

    public function deleteMember($projectid, $memberid) {
        $memberToDelete = $this->getMemberById($projectid, $memberid);
        if ($memberToDelete !== null) {
            $qb = $this->dbconnection->getQueryBuilder();
            if (count($this->getBillsOfMember($projectid, $memberid)) === 0) {
                $qb->delete('cospend_members')
                    ->where(
                        $qb->expr()->eq('id', $qb->createNamedParameter($memberid, IQueryBuilder::PARAM_INT))
                    );
                $req = $qb->execute();
                $qb = $qb->resetQueryParts();
            } elseif ($memberToDelete['activated']) {
                $qb->update('cospend_members');
                $qb->set('activated', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT));
                $qb->where(
                    $qb->expr()->eq('id', $qb->createNamedParameter($memberid, IQueryBuilder::PARAM_INT))
                )
                ->andWhere(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
                $req = $qb->execute();
                $qb = $qb->resetQueryParts();
            }
            return 'OK';
        }
        else {
            return ['Not Found'];
        }
    }

    private function getBillsOfMember(string $projectid, int $memberid): array {
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('bi.id')
            ->from('cospend_bill_owers', 'bo')
            ->innerJoin('bo', 'cospend_bills', 'bi', $qb->expr()->eq('bo.billid', 'bi.id'))
            ->innerJoin('bo', 'cospend_members', 'm', $qb->expr()->eq('bo.memberid', 'm.id'))
            ->where(
                $qb->expr()->eq('bi.payerid', $qb->createNamedParameter($memberid, IQueryBuilder::PARAM_INT))
            )
            ->orWhere(
                $qb->expr()->eq('bo.memberid', $qb->createNamedParameter($memberid, IQueryBuilder::PARAM_INT))
            );
        $req = $qb->execute();

        $billIds = [];
        while ($row = $req->fetch()) {
            array_push($billIds, $row['id']);
        }
        return $billIds;
    }

    public function getMemberByName($projectId, $name) {
        $member = null;
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'userid', 'name', 'weight', 'color', 'activated')
           ->from('cospend_members', 'm')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();

        while ($row = $req->fetch()){
            $dbMemberId = intval($row['id']);
            $dbWeight = floatval($row['weight']);
            $dbUserid = $row['userid'];
            $dbName = $row['name'];
            $dbActivated= intval($row['activated']);
            $dbColor = $row['color'];
            if ($dbColor === null) {
                $av = $this->avatarManager->getGuestAvatar($dbName);
                $dbColor = $av->avatarBackgroundColor($dbName);
            }
            else {
                $dbColor = $this->hexToRgb($dbColor);
            }
            $member = [
                    'activated' => ($dbActivated === 1),
                    'userid' => $dbUserid,
                    'name' => $dbName,
                    'id' => $dbMemberId,
                    'weight' => $dbWeight,
                    'color' => $dbColor
            ];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $member;
    }

    public function getMemberByUserid($projectId, $userid) {
        $member = null;
        if ($userid !== null) {
            $qb = $this->dbconnection->getQueryBuilder();
            $qb->select('id', 'userid', 'name', 'weight', 'color', 'activated')
               ->from('cospend_members', 'm')
               ->where(
                   $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
               )
               ->andWhere(
                   $qb->expr()->eq('userid', $qb->createNamedParameter($userid, IQueryBuilder::PARAM_STR))
               );
            $req = $qb->execute();

            while ($row = $req->fetch()){
                $dbMemberId = intval($row['id']);
                $dbWeight = floatval($row['weight']);
                $dbUserid = $row['userid'];
                $dbName = $row['name'];
                $dbActivated= intval($row['activated']);
                $dbColor = $row['color'];
                if ($dbColor === null) {
                    $av = $this->avatarManager->getGuestAvatar($dbName);
                    $dbColor = $av->avatarBackgroundColor($dbName);
                }
                else {
                    $dbColor = $this->hexToRgb($dbColor);
                }
                $member = [
                        'activated' => ($dbActivated === 1),
                        'userid' => $dbUserid,
                        'name' => $dbName,
                        'id' => $dbMemberId,
                        'weight' => $dbWeight,
                        'color' => $dbColor
                ];
                break;
            }
            $req->closeCursor();
            $qb = $qb->resetQueryParts();
        }
        return $member;
    }

    public function editBill($projectid, $billid, $date, $what, $payer, $payed_for,
                              $amount, $repeat, $paymentmode=null, $categoryid=null,
                              $repeatallactive=null, $repeatuntil=null, $timestamp=null,
                              $comment=null) {
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->update('cospend_bills');

        // set last modification timestamp
        $ts = (new \DateTime())->getTimestamp();
        $qb->set('lastchanged', $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT));

        // first check the bill exists
        if ($this->getBill($projectid, $billid) === null) {
            return ['message' => $this->trans->t('There is no such bill')];
        }
        // then edit the hell of it
        if ($what !== null && is_string($what) && $what !== '') {
            $qb->set('what', $qb->createNamedParameter($what, IQueryBuilder::PARAM_STR));
        }

        if ($comment !== null && is_string($comment)) {
            $qb->set('comment', $qb->createNamedParameter($comment, IQueryBuilder::PARAM_STR));
        }

        if ($repeat !== null && $repeat !== '') {
            if (in_array($repeat, ['n', 'd', 'w', 'b', 's', 'm', 'y'])) {
                $qb->set('repeat', $qb->createNamedParameter($repeat, IQueryBuilder::PARAM_STR));
            } else {
                return ['repeat' => $this->trans->t('Invalid value')];
            }
        }

        if ($repeatuntil !== null) {
            if ($repeatuntil === '') {
                $qb->set('repeatuntil', $qb->createNamedParameter(null, IQueryBuilder::PARAM_STR));
            }
            else {
                $qb->set('repeatuntil', $qb->createNamedParameter($repeatuntil, IQueryBuilder::PARAM_STR));
            }
        }
        if ($repeatallactive !== null && is_numeric($repeatallactive)) {
            $qb->set('repeatallactive', $qb->createNamedParameter($repeatallactive, IQueryBuilder::PARAM_INT));
        }
        if ($paymentmode !== null && is_string($paymentmode)) {
            $qb->set('paymentmode', $qb->createNamedParameter($paymentmode, IQueryBuilder::PARAM_STR));
        }
        if ($categoryid !== null && is_numeric($categoryid)) {
            $qb->set('categoryid', $qb->createNamedParameter($categoryid, IQueryBuilder::PARAM_INT));
        }
        // priority to timestamp (moneybuster might send both for a moment)
        if ($timestamp !== null && $timestamp !== '') {
            if (is_numeric($timestamp)) {
                $qb->set('timestamp', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT));
            }
            else {
                return ['timestamp' => $this->trans->t('Invalid value')];
            }
        }
        else if ($date !== null && $date !== '') {
            $dateTs = strtotime($date);
            if ($dateTs !== false) {
                $qb->set('timestamp', $qb->createNamedParameter($dateTs, IQueryBuilder::PARAM_INT));
            }
            else {
                return ['date' => $this->trans->t('Invalid value')];
            }
        }
        if ($amount !== null && $amount !== '' && is_numeric($amount)) {
            $qb->set('amount', $qb->createNamedParameter($amount, IQueryBuilder::PARAM_STR));
        }
        if ($payer !== null && $payer !== '' && is_numeric($payer)) {
            $member = $this->getMemberById($projectid, $payer);
            if ($member === null) {
                return ['payer' => $this->trans->t('Not a valid choice')];
            }
            else {
                $qb->set('payerid', $qb->createNamedParameter($payer, IQueryBuilder::PARAM_INT));
            }
        }

        $owerIds = null;
        // check owers
        if ($payed_for !== null && $payed_for !== '') {
            $owerIds = explode(',', $payed_for);
            if (count($owerIds) === 0) {
                return ['payed_for' => $this->trans->t('Invalid value')];
            }
            else {
                foreach ($owerIds as $owerId) {
                    if (!is_numeric($owerId)) {
                        return ['payed_for' => $this->trans->t('Invalid value')];
                    }
                    if ($this->getMemberById($projectid, $owerId) === null) {
                        return ['payed_for' => $this->trans->t('Not a valid choice')];
                    }
                }
            }
        }

        // do it already!
        $qb->where(
               $qb->expr()->eq('id', $qb->createNamedParameter($billid, IQueryBuilder::PARAM_INT))
           )
           ->andWhere(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
           );
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();

        // edit the bill owers
        if ($owerIds !== null) {
            // delete old bill owers
            $this->deleteBillOwersOfBill($billid);
            // insert bill owers
            foreach ($owerIds as $owerId) {
                $qb->insert('cospend_bill_owers')
                    ->values([
                        'billid' => $qb->createNamedParameter($billid, IQueryBuilder::PARAM_INT),
                        'memberid' => $qb->createNamedParameter($owerId, IQueryBuilder::PARAM_INT)
                    ]);
                $req = $qb->execute();
                $qb = $qb->resetQueryParts();
            }
        }

        $this->updateProjectLastChanged($projectid, $ts);

        return intval($billid);
    }

    /**
     * daily check of repeated bills
     */
    public function cronRepeatBills(?int $billId = null): array {
        $result = [];
        $projects = [];
        $now = new \DateTime();
        // in case cron job wasn't executed during several days,
        // continue trying to repeat bills as long as there was at least one repeated
        $continue = true;
        while ($continue) {
            $continue = false;
            // get bills whith repetition flag
            $qb = $this->dbconnection->getQueryBuilder();
            $qb->select('id', 'projectid', 'what', 'timestamp', 'amount', 'payerid', 'repeat', 'repeatallactive')
                ->from('cospend_bills', 'b')
                ->where(
                    $qb->expr()->neq('repeat', $qb->createNamedParameter('n', IQueryBuilder::PARAM_STR))
                );
            // we only repeat one bill
            if (!is_null($billId)) {
                $qb->andWhere(
                    $qb->expr()->eq('id', $qb->createNamedParameter($billId, IQueryBuilder::PARAM_INT))
                );
            }
            $req = $qb->execute();
            $bills = [];
            while ($row = $req->fetch()) {
                $id = $row['id'];
                $what = $row['what'];
                $repeat = $row['repeat'];
                $repeatallactive = $row['repeatallactive'];
                $timestamp = $row['timestamp'];
                $projectid = $row['projectid'];
                $bills[] = [
                    'id' => $id,
                    'what' => $what,
                    'repeat' => $repeat,
                    'repeatallactive' => $repeatallactive,
                    'projectid' => $projectid,
                    'timestamp' => $timestamp
                ];
            }
            $req->closeCursor();
            $qb = $qb->resetQueryParts();

            foreach ($bills as $bill) {
                // Use DateTimeImmutable instead of DateTime so that $billDate->add() returns a
                // new instance instead of modifying $billDate
                $billDate = \DateTimeImmutable::createFromFormat('U', $bill['timestamp']);
                $nextDate = $this->getNextRepetitionDate($bill, $billDate);

                // Unknown repeat interval
                if ($nextDate === null) {
                    continue;
                }

                // Repeat if $nextDate is in the past (or today)
                $diff = $now->diff($nextDate);
                if ($nextDate->format('Y-m-d') === $now->format('Y-m-d') || $diff->invert) {
                    $newBillId = $this->repeatBill($bill['projectid'], $bill['id'], $nextDate);
                    if (!array_key_exists($bill['projectid'], $projects)) {
                        $projects[$bill['projectid']] = $this->getProjectInfo($bill['projectid']);
                    }
                    $result[] = [
                        'new_bill_id' => $newBillId,
                        'date_orig' => $billDate->format('Y-m-d'),
                        'date_repeat' => $nextDate->format('Y-m-d'),
                        'what' => $bill['what'],
                        'project_name' => $projects[$bill['projectid']]['name'],
                    ];
                    $continue = true;
                    // when only repeating one bill, this newly created bill is the one we want to potentially repeat
                    $billId = $newBillId;
                }
            }
        }
        return $result;
    }

    /**
     * duplicate the bill today and give it the repeat flag
     * remove the repeat flag on original bill
     */
    private function repeatBill(string $projectid, int $billid, $datetime) {
        $bill = $this->getBill($projectid, $billid);

        $owerIds = [];
        if (intval($bill['repeatallactive']) === 1) {
            $pInfo = $this->getProjectInfo($projectid);
            foreach ($pInfo['active_members'] as $am) {
                $owerIds[] = $am['id'];
            }
        }
        else {
            foreach ($bill['owers'] as $ower) {
                if ($ower['activated']) {
                    $owerIds[] = $ower['id'];
                }
            }
        }
        $owerIdsStr = implode(',', $owerIds);
        // if all owers are disabled, don't try to repeat the bill and remove repeat flag
        if (count($owerIds) === 0) {
            $this->editBill($projectid, $billid, null, $bill['what'], $bill['payer_id'], null,
                            $bill['amount'], 'n', null, null, 0, null);
            return;
        }

        // if bill should be repeated until...
        if ($bill['repeatuntil'] !== null && $bill['repeatuntil'] !== '') {
            $untilDate = new \DateTime($bill['repeatuntil']);
            // TODO improve this, maybe don't produce bill after repeatuntil...
            if ($datetime >= $untilDate) {
                $bill['repeat'] = 'n';
            }
        }

        $newBillId = $this->addBill($projectid, null, $bill['what'], $bill['payer_id'],
                                    $owerIdsStr, $bill['amount'], $bill['repeat'], $bill['paymentmode'],
                                    $bill['categoryid'], $bill['repeatallactive'], $bill['repeatuntil'],
                                    $datetime->getTimestamp(), $bill['comment']);

        $billObj = $this->billMapper->find($newBillId);
        $this->activityManager->triggerEvent(
            ActivityManager::COSPEND_OBJECT_BILL, $billObj,
            ActivityManager::SUBJECT_BILL_CREATE,
            []
        );

        // now we can remove repeat flag on original bill
        $this->editBill($projectid, $billid, null, $bill['what'], $bill['payer_id'], null,
                        $bill['amount'], 'n', null, null, 0, null);
        return $newBillId;
    }

    private function getNextRepetitionDate(array $bill, \DateTimeImmutable $billDate) {
        $nextDate = null;
        switch ($bill['repeat']) {
            case 'd':
                $nextDate = $billDate->add(new \DateInterval('P1D'));
                break;

            case 'w':
                $nextDate = $billDate->add(new \DateInterval('P7D'));
                break;

            // bi weekly
            case 'b':
                $nextDate = $billDate->add(new \DateInterval('P14D'));
                break;

            // semi monthly
            case 's':
                $day = intval($billDate->format('d'));
                $month = intval($billDate->format('m'));
                $year = intval($billDate->format('Y'));

                $nextDate = new \DateTime();
                // first of next month
                if ($day >= 15) {
                    if ($month === 12) {
                        $nextYear = $year + 1;
                        $nextMonth = 1;
                        $nextDate->setDate($nextYear, $nextMonth, 1);
                    } else {
                        $nextMonth = $month + 1;
                        $nextDate->setDate($year, $nextMonth, 1);
                    }
                } else {
                    // 15 of same month
                    $nextDate->setDate($year, $month, 15);
                }
                break;

            case 'm':
                if (intval($billDate->format('m')) === 12) {
                    $nextYear = $billDate->format('Y') + 1;
                    $nextMonth = 1;
                } else {
                    $nextYear = $billDate->format('Y');
                    $nextMonth = $billDate->format('m') + 1;
                }

                // same day of month if possible, otherwise at end of month
                $nextDate = new \DateTime();
                $nextDate->setDate($nextYear, $nextMonth, 1);
                if ($billDate->format('d') > $nextDate->format('t')) {
                    $nextDate->setDate($nextYear, $nextMonth, $nextDate->format('t'));
                }
                else {
                    $nextDate->setDate($nextYear, $nextMonth, $billDate->format('d'));
                }
                break;

            case 'y':
                $nextYear = $billDate->format('Y') + 1;
                $nextMonth = $billDate->format('m');

                // same day of month if possible, otherwise at end of month + same month
                $nextDate = new \DateTime();
                $nextDate->setDate($billDate->format('Y') + 1, $billDate->format('m'), 1);
                if ($billDate->format('d') > $nextDate->format('t')) {
                    $nextDate->setDate($nextYear, $nextMonth, $nextDate->format('t'));
                }
                else {
                    $nextDate->setDate($nextYear, $nextMonth, $billDate->format('d'));
                }
                break;
        }

        return $nextDate;
    }

    public function addCategory(string $projectid, string $name, ?string $icon, string $color, ?int $order = 0): int {
        $qb = $this->dbconnection->getQueryBuilder();

        $encIcon = $icon;
        if ($icon !== null && $icon !== '') {
            $encIcon = urlencode($icon);
        }
        $qb->insert('cospend_project_categories')
            ->values([
                'projectid' => $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR),
                'encoded_icon' => $qb->createNamedParameter($encIcon, IQueryBuilder::PARAM_STR),
                'color' => $qb->createNamedParameter($color, IQueryBuilder::PARAM_STR),
                'name' => $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR),
                'order' => $qb->createNamedParameter(is_null($order) ? 0 : $order, IQueryBuilder::PARAM_INT)
            ]);
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();

        $insertedCategoryId = intval($qb->getLastInsertId());
        $response = $insertedCategoryId;

        return $response;
    }

    private function getCategory($projectId, $categoryid) {
        $category = null;

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'name', 'projectid', 'encoded_icon', 'color')
           ->from('cospend_project_categories', 'c')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('id', $qb->createNamedParameter($categoryid, IQueryBuilder::PARAM_INT))
           );
        $req = $qb->execute();

        while ($row = $req->fetch()) {
            $dbCategoryId = intval($row['id']);
            $dbName = $row['name'];
            $dbIcon = urldecode($row['encoded_icon']);
            $dbColor = $row['color'];
            $category = [
                    'name' => $dbName,
                    'icon' => $dbIcon,
                    'color' => $dbColor,
                    'id' => $dbCategoryId,
                    'projectid' => $projectId
            ];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $category;
    }

    public function deleteCategory($projectid, $categoryid) {
        $categoryToDelete = $this->getCategory($projectid, $categoryid);
        if ($categoryToDelete !== null) {
            $qb = $this->dbconnection->getQueryBuilder();
            $qb->delete('cospend_project_categories')
               ->where(
                   $qb->expr()->eq('id', $qb->createNamedParameter($categoryid, IQueryBuilder::PARAM_INT))
               )
               ->andWhere(
                   $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
               );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            // then get rid of this category in bills
            $qb = $this->dbconnection->getQueryBuilder();
            $qb->update('cospend_bills');
            $qb->set('categoryid', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT));
            $qb->where(
                $qb->expr()->eq('categoryid', $qb->createNamedParameter($categoryid, IQueryBuilder::PARAM_INT))
            )
            ->andWhere(
                $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
            );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            return $categoryid;
        }
        else {
            return ['message' => $this->trans->t('Not found')];
        }
    }

    public function saveCategoryOrder(string $projectid, array $order): bool {
        $qb = $this->dbconnection->getQueryBuilder();
        foreach ($order as $o) {
            $qb->update('cospend_project_categories');
            $qb->set('order', $qb->createNamedParameter($o['order'], IQueryBuilder::PARAM_INT));
            $qb->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($o['id'], IQueryBuilder::PARAM_INT))
            )
            ->andWhere(
                $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
            );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();
        }
        return true;
    }

    public function editCategory($projectid, $categoryid, $name, $icon, $color) {
        if ($name !== null && $name !== '') {
            $encIcon = $icon;
            if ($icon !== null && $icon !== '') {
                $encIcon = urlencode($icon);
            }
            if ($this->getCategory($projectid, $categoryid) !== null) {
                $qb = $this->dbconnection->getQueryBuilder();
                $qb->update('cospend_project_categories');
                $qb->set('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR));
                $qb->set('encoded_icon', $qb->createNamedParameter($encIcon, IQueryBuilder::PARAM_STR));
                $qb->set('color', $qb->createNamedParameter($color, IQueryBuilder::PARAM_STR));
                $qb->where(
                    $qb->expr()->eq('id', $qb->createNamedParameter($categoryid, IQueryBuilder::PARAM_INT))
                )
                ->andWhere(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
                $req = $qb->execute();
                $qb = $qb->resetQueryParts();

                $editedCategory = $this->getCategory($projectid, $categoryid);

                return $editedCategory;
            }
            else {
                return ['message' => $this->trans->t('This project have no such category')];
            }
        }
        else {
            return ['message' => $this->trans->t('Incorrect field values')];
        }
    }

    public function addCurrency($projectid, $name, $rate) {
        $qb = $this->dbconnection->getQueryBuilder();

        $qb->insert('cospend_currencies')
            ->values([
                'projectid' => $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR),
                'name' => $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR),
                'exchange_rate' => $qb->createNamedParameter($rate, IQueryBuilder::PARAM_STR)
            ]);
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();

        $insertedCurrencyId = intval($qb->getLastInsertId());
        $response = $insertedCurrencyId;

        return $response;
    }

    private function getCurrency($projectId, $currencyid) {
        $currency = null;

        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'name', 'exchange_rate', 'projectid')
           ->from('cospend_currencies', 'c')
           ->where(
               $qb->expr()->eq('projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->eq('id', $qb->createNamedParameter($currencyid, IQueryBuilder::PARAM_INT))
           );
        $req = $qb->execute();

        while ($row = $req->fetch()) {
            $dbCurrencyId = intval($row['id']);
            $dbRate = floatval($row['exchange_rate']);
            $dbName = $row['name'];
            $currency = [
                    'name' => $dbName,
                    'id' => $dbCurrencyId,
                    'exchange_rate' => $dbRate,
                    'projectid' => $projectId
            ];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();
        return $currency;
    }

    public function deleteCurrency($projectid, $currencyid) {
        $currencyToDelete = $this->getCurrency($projectid, $currencyid);
        if ($currencyToDelete !== null) {
            $qb = $this->dbconnection->getQueryBuilder();
            $qb->delete('cospend_currencies')
               ->where(
                   $qb->expr()->eq('id', $qb->createNamedParameter($currencyid, IQueryBuilder::PARAM_INT))
               )
               ->andWhere(
                   $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
               );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            return $currencyid;
        }
        else {
            return ['message' => $this->trans->t('Not found')];
        }
    }

    public function editCurrency($projectid, $currencyid, $name, $exchange_rate) {
        if ($name !== null && $name !== '' && is_numeric($exchange_rate)) {
            if ($this->getCurrency($projectid, $currencyid) !== null) {
                $qb = $this->dbconnection->getQueryBuilder();
                $qb->update('cospend_currencies');
                $qb->set('exchange_rate', $qb->createNamedParameter($exchange_rate, IQueryBuilder::PARAM_STR));
                $qb->set('name', $qb->createNamedParameter($name, IQueryBuilder::PARAM_STR));
                $qb->where(
                    $qb->expr()->eq('id', $qb->createNamedParameter($currencyid, IQueryBuilder::PARAM_INT))
                )
                ->andWhere(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                );
                $req = $qb->execute();
                $qb = $qb->resetQueryParts();

                $editedCurrency = $this->getCurrency($projectid, $currencyid);

                return $editedCurrency;
            }
            else {
                return ['message' => $this->trans->t('This project have no such currency')];
            }
        }
        else {
            return ['message' => $this->trans->t('Incorrect field values')];
        }
    }

    public function addUserShare($projectid, $userid, $fromUserId, $accesslevel = ACCESS_PARTICIPANT, $manually_added = true) {
        // check if userId exists
        $userIds = [];
        foreach ($this->userManager->search('') as $u) {
            if ($u->getUID() !== $fromUserId) {
                array_push($userIds, $u->getUID());
            }
        }
        if ($userid !== '' && in_array($userid, $userIds)) {
            $name = $this->userManager->get($userid)->getDisplayName();
            $qb = $this->dbconnection->getQueryBuilder();
            $projectInfo = $this->getProjectInfo($projectid);
            // check if someone tries to share the project with its owner
            if ($userid !== $projectInfo['userid']) {
                // check if user share exists
                $qb->select('userid', 'projectid')
                    ->from('cospend_shares', 's')
                    ->where(
                        $qb->expr()->eq('type', $qb->createNamedParameter('u', IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('userid', $qb->createNamedParameter($userid, IQueryBuilder::PARAM_STR))
                    );
                $req = $qb->execute();
                $dbuserId = null;
                while ($row = $req->fetch()){
                    $dbuserId = $row['userid'];
                    break;
                }
                $req->closeCursor();
                $qb = $qb->resetQueryParts();

                if ($dbuserId === null) {
                    if ($this->getUserMaxAccessLevel($fromUserId, $projectid) >= $accesslevel) {
                        $qb->insert('cospend_shares')
                            ->values([
                                'projectid' => $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR),
                                'userid' => $qb->createNamedParameter($userid, IQueryBuilder::PARAM_STR),
                                'type' => $qb->createNamedParameter('u', IQueryBuilder::PARAM_STR),
                                'accesslevel' => $qb->createNamedParameter($accesslevel, IQueryBuilder::PARAM_INT),
                                'manually_added' => $qb->createNamedParameter($manually_added ? 1 : 0, IQueryBuilder::PARAM_INT),
                            ]);
                        $req = $qb->execute();
                        $qb = $qb->resetQueryParts();

                        $insertedShareId = intval($qb->getLastInsertId());
                        $response = [
                            'id' => $insertedShareId,
                            'name' => $name
                        ];

                        // activity
                        $projectObj = $this->projectMapper->find($projectid);
                        $this->activityManager->triggerEvent(
                            ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
                            ActivityManager::SUBJECT_PROJECT_SHARE,
                            ['who' => $userid, 'type' => 'u']
                        );

                        // SEND NOTIFICATION
                        $manager = \OC::$server->getNotificationManager();
                        $notification = $manager->createNotification();

                        $acceptAction = $notification->createAction();
                        $acceptAction->setLabel('accept')
                            ->setLink('/apps/cospend', 'GET');

                        $declineAction = $notification->createAction();
                        $declineAction->setLabel('decline')
                            ->setLink('/apps/cospend', 'GET');

                        $notification->setApp('cospend')
                            ->setUser($userid)
                            ->setDateTime(new \DateTime())
                            ->setObject('addusershare', $projectid)
                            ->setSubject('add_user_share', [$fromUserId, $projectInfo['name']])
                            ->addAction($acceptAction)
                            ->addAction($declineAction)
                            ;

                        $manager->notify($notification);

                        return $response;
                    } else {
                        return $this->trans->t('You are not authorized to give such access level');
                    }
                } else {
                    return $this->trans->t('Already shared with this user');
                }
            } else {
                return $this->trans->t('Impossible to share the project with its owner');
            }
        } else {
            return $this->trans->t('No such user');
        }
    }

    public function addPublicShare($projectid) {
        $qb = $this->dbconnection->getQueryBuilder();
        // generate token
        $token = md5($projectid.rand());

        $qb->insert('cospend_shares')
            ->values([
                'projectid' => $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR),
                'userid' => $qb->createNamedParameter($token, IQueryBuilder::PARAM_STR),
                'type' => $qb->createNamedParameter('l', IQueryBuilder::PARAM_STR)
            ]);
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();

        $insertedShareId = intval($qb->getLastInsertId());
        $response = [
            'token' => $token,
            'id' => $insertedShareId
        ];

        //// activity
        //$projectObj = $this->projectMapper->find($projectid);
        //$this->activityManager->triggerEvent(
        //    ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
        //    ActivityManager::SUBJECT_PROJECT_SHARE,
        //    ['who' => $userid, 'type' => 'u']
        //);

        //// SEND NOTIFICATION
        //$projectInfo = $this->getProjectInfo($projectid);
        //$manager = \OC::$server->getNotificationManager();
        //$notification = $manager->createNotification();

        //$acceptAction = $notification->createAction();
        //$acceptAction->setLabel('accept')
        //    ->setLink('/apps/cospend', 'GET');

        //$declineAction = $notification->createAction();
        //$declineAction->setLabel('decline')
        //    ->setLink('/apps/cospend', 'GET');

        //$notification->setApp('cospend')
        //    ->setUser($userid)
        //    ->setDateTime(new \DateTime())
        //    ->setObject('addusershare', $projectid)
        //    ->setSubject('add_user_share', [$fromUserId, $projectInfo['name']])
        //    ->addAction($acceptAction)
        //    ->addAction($declineAction)
        //    ;

        //$manager->notify($notification);

        return $response;
    }

    public function editShareAccessLevel($projectid, $shid, $accesslevel) {
        // check if user share exists
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'projectid')
            ->from('cospend_shares', 's')
            ->where(
                $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
            );
        $req = $qb->execute();
        $dbId = null;
        while ($row = $req->fetch()){
            $dbId = $row['id'];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        if ($dbId !== null) {
            // set the accesslevel
            $qb->update('cospend_shares')
                ->set('accesslevel', $qb->createNamedParameter($accesslevel, IQueryBuilder::PARAM_INT))
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                )
                ->andWhere(
                    $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            return 'OK';
        }
        else {
            return ['message' => $this->trans->t('No such share')];
        }
    }

    public function editGuestAccessLevel($projectid, $accesslevel) {
        // check if project exists
        $qb = $this->dbconnection->getQueryBuilder();

        // set the access level
        $qb->update('cospend_projects')
            ->set('guestaccesslevel', $qb->createNamedParameter($accesslevel, IQueryBuilder::PARAM_INT))
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
            );
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();

        $response = 'OK';

        return $response;
    }

    public function deleteUserShare($projectid, $shid, $fromUserId) {
        // check if user share exists
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'userid', 'projectid')
            ->from('cospend_shares', 's')
            ->where(
                $qb->expr()->eq('type', $qb->createNamedParameter('u', IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
            );
        $req = $qb->execute();
        $dbId = null;
        $dbuserId = null;
        while ($row = $req->fetch()){
            $dbId = $row['id'];
            $dbuserId = $row['userid'];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        if ($dbId !== null) {
            // delete
            $qb->delete('cospend_shares')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                )
                ->andWhere(
                    $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
                )
                ->andWhere(
                    $qb->expr()->eq('type', $qb->createNamedParameter('u', IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            $response = 'OK';

            // activity
            $projectObj = $this->projectMapper->find($projectid);
            $this->activityManager->triggerEvent(
                ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
                ActivityManager::SUBJECT_PROJECT_UNSHARE,
                ['who' => $dbuserId, 'type' => 'u']
            );

            // SEND NOTIFICATION
            $projectInfo = $this->getProjectInfo($projectid);

            $manager = \OC::$server->getNotificationManager();
            $notification = $manager->createNotification();

            $acceptAction = $notification->createAction();
            $acceptAction->setLabel('accept')
                ->setLink('/apps/cospend', 'GET');

            $declineAction = $notification->createAction();
            $declineAction->setLabel('decline')
                ->setLink('/apps/cospend', 'GET');

            $notification->setApp('cospend')
                ->setUser($dbuserId)
                ->setDateTime(new \DateTime())
                ->setObject('deleteusershare', $projectid)
                ->setSubject('delete_user_share', [$fromUserId, $projectInfo['name']])
                ->addAction($acceptAction)
                ->addAction($declineAction)
                ;

            $manager->notify($notification);

            return $response;
        }
        else {
            return ['message' => $this->trans->t('No such share')];
        }
    }

    public function deletePublicShare($projectid, $shid) {
        // check if public share exists
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'userid', 'projectid')
            ->from('cospend_shares', 's')
            ->where(
                $qb->expr()->eq('type', $qb->createNamedParameter('l', IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
            );
        $req = $qb->execute();
        $dbId = null;
        $dbToken = null;
        while ($row = $req->fetch()){
            $dbId = $row['id'];
            $dbToken = $row['userid'];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        if ($dbId !== null) {
            // delete
            $qb->delete('cospend_shares')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                )
                ->andWhere(
                    $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
                )
                ->andWhere(
                    $qb->expr()->eq('type', $qb->createNamedParameter('l', IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            $response = 'OK';

            //// activity
            //$projectObj = $this->projectMapper->find($projectid);
            //$this->activityManager->triggerEvent(
            //    ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
            //    ActivityManager::SUBJECT_PROJECT_UNSHARE,
            //    ['who' => $dbuserId, 'type' => 'u']
            //);

            //// SEND NOTIFICATION
            //$projectInfo = $this->getProjectInfo($projectid);

            //$manager = \OC::$server->getNotificationManager();
            //$notification = $manager->createNotification();

            //$acceptAction = $notification->createAction();
            //$acceptAction->setLabel('accept')
            //    ->setLink('/apps/cospend', 'GET');

            //$declineAction = $notification->createAction();
            //$declineAction->setLabel('decline')
            //    ->setLink('/apps/cospend', 'GET');

            //$notification->setApp('cospend')
            //    ->setUser($dbuserId)
            //    ->setDateTime(new \DateTime())
            //    ->setObject('deleteusershare', $projectid)
            //    ->setSubject('delete_user_share', [$fromUserId, $projectInfo['name']])
            //    ->addAction($acceptAction)
            //    ->addAction($declineAction)
            //    ;

            //$manager->notify($notification);

            return $response;
        }
        else {
            return ['message' => $this->trans->t('No such shared access')];
        }
    }

    public function addGroupShare($projectid, $groupid, $fromUserId) {
        // check if groupId exists
        $groupIds = [];
        foreach($this->groupManager->search('') as $g) {
            array_push($groupIds, $g->getGID());
        }
        if ($groupid !== '' && in_array($groupid, $groupIds)) {
            $name = $this->groupManager->get($groupid)->getDisplayName();
            $qb = $this->dbconnection->getQueryBuilder();
            // check if user share exists
            $qb->select('userid', 'projectid')
                ->from('cospend_shares', 's')
                ->where(
                    $qb->expr()->eq('type', $qb->createNamedParameter('g', IQueryBuilder::PARAM_STR))
                )
                ->andWhere(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                )
                ->andWhere(
                    $qb->expr()->eq('userid', $qb->createNamedParameter($groupid, IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $dbGroupId = null;
            while ($row = $req->fetch()){
                $dbGroupId = $row['userid'];
                break;
            }
            $req->closeCursor();
            $qb = $qb->resetQueryParts();

            if ($dbGroupId === null) {
                $qb->insert('cospend_shares')
                    ->values([
                        'projectid' => $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR),
                        'userid' => $qb->createNamedParameter($groupid, IQueryBuilder::PARAM_STR),
                        'type' => $qb->createNamedParameter('g', IQueryBuilder::PARAM_STR)
                    ]);
                $req = $qb->execute();
                $qb = $qb->resetQueryParts();

                $insertedShareId = intval($qb->getLastInsertId());
                $response = [
                    'id' => $insertedShareId,
                    'name' => $name
                ];

                // activity
                $projectObj = $this->projectMapper->find($projectid);
                $this->activityManager->triggerEvent(
                    ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
                    ActivityManager::SUBJECT_PROJECT_SHARE,
                    ['who' => $groupid, 'type' => 'g']
                );

                return $response;
            }
            else {
                return $this->trans->t('Already shared with this group');
            }
        }
        else {
            return $this->trans->t('No such group');
        }
    }

    public function deleteGroupShare($projectid, $shid, $fromUserId) {
        // check if group share exists
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('userid', 'projectid', 'id')
            ->from('cospend_shares', 's')
            ->where(
                $qb->expr()->eq('type', $qb->createNamedParameter('g', IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
            );
        $req = $qb->execute();
        $dbGroupId = null;
        $dbId = null;
        while ($row = $req->fetch()){
            $dbGroupId = $row['userid'];
            $dbId = $row['id'];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        if ($dbGroupId !== null) {
            // delete
            $qb->delete('cospend_shares')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                )
                ->andWhere(
                    $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
                )
                ->andWhere(
                    $qb->expr()->eq('type', $qb->createNamedParameter('g', IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            $response = 'OK';

            // activity
            $projectObj = $this->projectMapper->find($projectid);
            $this->activityManager->triggerEvent(
                ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
                ActivityManager::SUBJECT_PROJECT_UNSHARE,
                ['who' => $dbGroupId, 'type' => 'g']
            );

            return $response;
        }
        else {
            return ['message' => $this->trans->t('No such share')];
        }
    }

    public function addCircleShare($projectid, $circleid, $fromUserId) {
        // check if circleId exists
        $circlesEnabled = \OC::$server->getAppManager()->isEnabledForUser('circles');
        $circleName = '';
        if ($circlesEnabled) {
            $cs = \OCA\Circles\Api\v1\Circles::listCircles(\OCA\Circles\Model\Circle::CIRCLES_ALL, '', 0);
            $exists = false;
            foreach ($cs as $c) {
                if ($c->getUniqueId() === $circleid) {
                    if ($c->getType() === \OCA\Circles\Model\Circle::CIRCLES_PERSONAL) {
                        return ['message' => $this->trans->t('Sharing with personal circles is not supported')];
                    }
                    else {
                        $exists = true;
                        $circleName = $c->getName();
                    }
                }
            }
            if ($circleid !== '' && $exists) {
                $qb = $this->dbconnection->getQueryBuilder();
                // check if circle share exists
                $qb->select('userid', 'projectid')
                    ->from('cospend_shares', 's')
                    ->where(
                        $qb->expr()->eq('type', $qb->createNamedParameter('c', IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                    )
                    ->andWhere(
                        $qb->expr()->eq('userid', $qb->createNamedParameter($circleid, IQueryBuilder::PARAM_STR))
                    );
                $req = $qb->execute();
                $dbCircleId = null;
                while ($row = $req->fetch()){
                    $dbCircleId = $row['userid'];
                    break;
                }
                $req->closeCursor();
                $qb = $qb->resetQueryParts();

                if ($dbCircleId === null) {
                    $qb->insert('cospend_shares')
                        ->values([
                            'projectid' => $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR),
                            'userid' => $qb->createNamedParameter($circleid, IQueryBuilder::PARAM_STR),
                            'type' => $qb->createNamedParameter('c', IQueryBuilder::PARAM_STR)
                        ]);
                    $req = $qb->execute();
                    $qb = $qb->resetQueryParts();

                    $insertedShareId = intval($qb->getLastInsertId());
                    $response = [
                        'id' => $insertedShareId,
                        'name' => $circleName
                    ];

                    // activity
                    $projectObj = $this->projectMapper->find($projectid);
                    $this->activityManager->triggerEvent(
                        ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
                        ActivityManager::SUBJECT_PROJECT_SHARE,
                        ['who' => $circleid, 'type' => 'c']
                    );

                    return $response;
                }
                else {
                    return $this->trans->t('Already shared with this circle');
                }
            }
            else {
                return $this->trans->t('No such circle');
            }
        }
        else {
            return $this->trans->t('Circles app is not enabled');
        }
    }

    public function deleteCircleShare($projectid, $shid, $fromUserId) {
        // check if circle share exists
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('userid', 'projectid', 'id')
            ->from('cospend_shares', 's')
            ->where(
                $qb->expr()->eq('type', $qb->createNamedParameter('c', IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
            );
        $req = $qb->execute();
        $dbCircleId = null;
        $dbId = null;
        while ($row = $req->fetch()){
            $dbCircleId = $row['userid'];
            $dbId = $row['id'];
            break;
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        if ($dbCircleId !== null) {
            // delete
            $qb->delete('cospend_shares')
                ->where(
                    $qb->expr()->eq('projectid', $qb->createNamedParameter($projectid, IQueryBuilder::PARAM_STR))
                )
                ->andWhere(
                    $qb->expr()->eq('id', $qb->createNamedParameter($shid, IQueryBuilder::PARAM_INT))
                )
                ->andWhere(
                    $qb->expr()->eq('type', $qb->createNamedParameter('c', IQueryBuilder::PARAM_STR))
                );
            $req = $qb->execute();
            $qb = $qb->resetQueryParts();

            $response = 'OK';

            // activity
            $projectObj = $this->projectMapper->find($projectid);
            $this->activityManager->triggerEvent(
                ActivityManager::COSPEND_OBJECT_PROJECT, $projectObj,
                ActivityManager::SUBJECT_PROJECT_UNSHARE,
                ['who' => $dbCircleId, 'type' => 'c']
            );
        }
        else {
            $response = ['message' => $this->trans->t('No such share')];
        }

        return $response;
    }

    public function exportCsvSettlement(string $projectid, string $userId, ?int $centeredOn = null, ?int $maxTimestamp = null) {
        // create export directory if needed
        $outPath = $this->config->getUserValue($userId, 'cospend', 'outputDirectory', '/Cospend');
        $userFolder = \OC::$server->getUserFolder($userId);
        $msg = $this->createAndCheckExportDirectory($userFolder, $outPath);
        if ($msg !== '') {
            $response = ['message' => $msg];
            return $response;
        }
        $folder = $userFolder->get($outPath);

        // create file
        if ($folder->nodeExists($projectid.'-settlement.csv')) {
            $folder->get($projectid.'-settlement.csv')->delete();
        }
        $file = $folder->newFile($projectid.'-settlement.csv');
        $handler = $file->fopen('w');
        fwrite($handler, '"' . $this->trans->t('Who pays?') . '","' . $this->trans->t('To whom?') . '","' . $this->trans->t('How much?') . '"' . "\n");
        $settlement = $this->getProjectSettlement($projectid, $centeredOn, $maxTimestamp);
        $transactions = $settlement['transactions'];

        $members = $this->getMembers($projectid);
        $memberIdToName = [];
        foreach ($members as $member) {
            $memberIdToName[$member['id']] = $member['name'];
        }

        foreach ($transactions as $transaction) {
            fwrite($handler, '"'.$memberIdToName[$transaction['from']].'","'.$memberIdToName[$transaction['to']].'",'.floatval($transaction['amount'])."\n");
        }

        fclose($handler);
        $file->touch();
        $response = ['path' => $outPath.'/'.$projectid.'-settlement.csv'];
        return $response;
    }

    private function createAndCheckExportDirectory($userFolder, $outPath) {
        if (!$userFolder->nodeExists($outPath)) {
            $userFolder->newFolder($outPath);
        }
        if ($userFolder->nodeExists($outPath)) {
            $folder = $userFolder->get($outPath);
            if ($folder->getType() !== \OCP\Files\FileInfo::TYPE_FOLDER) {
                return $this->trans->t('%1$s is not a folder', [$outPath]);
            }
            else if (!$folder->isCreatable()) {
                return $this->trans->t('%1$s is not writeable', [$outPath]);
            }
            else {
                return '';
            }
        }
        else {
            return $this->trans->t('Impossible to create %1$s', [$outPath]);
        }
    }

    public function exportCsvStatistics($projectid, $userId, $tsMin=null, $tsMax=null, $paymentMode=null, $category=null,
                                        $amountMin=null, $amountMax=null, $showDisabled='1', $currencyId=null) {
        // create export directory if needed
        $outPath = $this->config->getUserValue($userId, 'cospend', 'outputDirectory', '/Cospend');
        $userFolder = \OC::$server->getUserFolder($userId);
        $msg = $this->createAndCheckExportDirectory($userFolder, $outPath);
        if ($msg !== '') {
            $response = ['message' => $msg];
            return $response;
        }
        $folder = $userFolder->get($outPath);

        // create file
        if ($folder->nodeExists($projectid.'-stats.csv')) {
            $folder->get($projectid.'-stats.csv')->delete();
        }
        $file = $folder->newFile($projectid.'-stats.csv');
        $handler = $file->fopen('w');
        fwrite($handler, $this->trans->t('Member name').','. $this->trans->t('Paid').','. $this->trans->t('Spent').','. $this->trans->t('Balance')."\n");
        $allStats = $this->getProjectStatistics($projectid, 'lowername', $tsMin, $tsMax, $paymentMode,
                                                $category, $amountMin, $amountMax, $showDisabled, $currencyId);
        $stats = $allStats['stats'];
        if (!is_array($stats)) {
        }

        foreach ($stats as $stat) {
            fwrite($handler, '"'.$stat['member']['name'].'",'.floatval($stat['paid']).','.floatval($stat['spent']).','.floatval($stat['balance'])."\n");
        }

        fclose($handler);
        $file->touch();
        $response = ['path' => $outPath.'/'.$projectid.'-stats.csv'];
        return $response;
    }

    public function exportCsvProject($projectid, $name, $userId) {
        // create export directory if needed
        $outPath = $this->config->getUserValue($userId, 'cospend', 'outputDirectory', '/Cospend');
        $userFolder = \OC::$server->getUserFolder($userId);
        $msg = $this->createAndCheckExportDirectory($userFolder, $outPath);
        if ($msg !== '') {
            $response = ['message' => $msg];
            return $response;
        }
        $folder = $userFolder->get($outPath);

        $projectInfo = $this->getProjectInfo($projectid);

        // create file
        $filename = $projectid.'.csv';
        if ($name !== null) {
            $filename = $name;
            if (!endswith($filename, '.csv')) {
                $filename .= '.csv';
            }
        }
        if ($folder->nodeExists($filename)) {
            $folder->get($filename)->delete();
        }
        $file = $folder->newFile($filename);
        $handler = $file->fopen('w');
        fwrite($handler, "what,amount,date,timestamp,payer_name,payer_weight,payer_active,owers,repeat,repeatallactive,repeatuntil,categoryid,paymentmode,comment\n");
        $members = $projectInfo['members'];
        $memberIdToName = [];
        $memberIdToWeight = [];
        $memberIdToActive = [];
        foreach ($members as $member) {
            $memberIdToName[$member['id']] = $member['name'];
            $memberIdToWeight[$member['id']] = $member['weight'];
            $memberIdToActive[$member['id']] = intval($member['activated']);
            fwrite($handler, 'deleteMeIfYouWant,1,1970-01-01,0,"'.$member['name'].'",'.floatval($member['weight']).','.
                              intval($member['activated']).',"'.$member['name'].'",n,,,,,'."\n");
        }
        $bills = $this->getBills($projectid);
        foreach ($bills as $bill) {
            $owerNames = [];
            foreach ($bill['owers'] as $ower) {
                array_push($owerNames, $ower['name']);
            }
            $owersTxt = implode(', ', $owerNames);

            $payer_id = $bill['payer_id'];
            $payer_name = $memberIdToName[$payer_id];
            $payer_weight = $memberIdToWeight[$payer_id];
            $payer_active = $memberIdToActive[$payer_id];
            $dateTime = \DateTime::createFromFormat('U', $bill['timestamp']);
            $oldDateStr = $dateTime->format('Y-m-d');
            fwrite($handler, '"'.$bill['what'].'",'.floatval($bill['amount']).','.$oldDateStr.','.$bill['timestamp'].
                             ',"'.$payer_name.'",'.
                             floatval($payer_weight).','.$payer_active.',"'.$owersTxt.'",'.$bill['repeat'].
                             ','.$bill['repeatallactive'].','.
                             $bill['repeatuntil'].','.$bill['categoryid'].','.$bill['paymentmode'].
                             ',"'.urlencode($bill['comment']).'"'."\n");
        }

        // write categories
        $categories = $projectInfo['categories'];
        if (count($categories) > 0) {
            fwrite($handler, "\n");
            fwrite($handler, "categoryname,categoryid,icon,color\n");
            foreach ($categories as $id => $cat) {
                fwrite($handler, '"'.$cat['name'].'",'.intval($id).',"'.$cat['icon'].'","'.$cat['color'].'"'."\n");
            }
        }

        // write currencies
        $currencies = $projectInfo['currencies'];
        if (count($currencies) > 0) {
            fwrite($handler, "\n");
            fwrite($handler, "currencyname,exchange_rate\n");
            // main currency
            fwrite($handler, '"'.$projectInfo['currencyname'].'",1'."\n");
            foreach ($currencies as $cur) {
                fwrite($handler, '"'.$cur['name'].'",'.floatval($cur['exchange_rate'])."\n");
            }
        }

        fclose($handler);
        $file->touch();
        $response = ['path' => $outPath.'/'.$filename];
        return $response;
    }

    public function importCsvProject($path, $userId) {
        $cleanPath = str_replace(array('../', '..\\'), '',  $path);
        $userFolder = \OC::$server->getUserFolder($userId);
        if ($userFolder->nodeExists($cleanPath)) {
            $file = $userFolder->get($cleanPath);
            if ($file->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
                if (($handle = $file->fopen('r')) !== false) {
                    $columns = [];
                    $membersWeight = [];
                    $membersActive = [];
                    $bills = [];
                    $currencies = [];
                    $mainCurrencyName = null;
                    $categories = [];
                    $categoryIdConv = [];
                    $previousLineEmpty = false;
                    $currentSection = null;
                    $row = 0;
                    while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                        if ($data === [null]) {
                            $previousLineEmpty = true;
                        }
                        // determine which section we're entering
                        else if ($row === 0 || $previousLineEmpty) {
                            $previousLineEmpty = false;
                            $nbCol = count($data);
                            $columns = [];
                            for ($c=0; $c < $nbCol; $c++) {
                                $columns[$data[$c]] = $c;
                            }
                            if (array_key_exists('what', $columns) and
                                array_key_exists('amount', $columns) and
                                (array_key_exists('date', $columns) || array_key_exists('timestamp', $columns)) and
                                array_key_exists('payer_name', $columns) and
                                array_key_exists('payer_weight', $columns) and
                                array_key_exists('owers', $columns)
                            ) {
                                $currentSection = 'bills';
                            }
                            else if (array_key_exists('icon', $columns) and
                                     array_key_exists('color', $columns) and
                                     array_key_exists('categoryid', $columns) and
                                     array_key_exists('categoryname', $columns)
                            ) {
                                $currentSection = 'categories';
                            }
                            else if (array_key_exists('exchange_rate', $columns) and
                                     array_key_exists('currencyname', $columns)
                            ) {
                                $currentSection = 'currencies';
                            }
                            else {
                                fclose($handle);
                                return ['message' => $this->trans->t('Malformed CSV, bad column names at line %1$s', [$row + 1])];
                            }
                        }
                        // normal line : bill or category
                        else {
                            $previousLineEmpty = false;
                            if ($currentSection === 'categories') {
                                $icon = $data[$columns['icon']];
                                $color = $data[$columns['color']];
                                $categoryid = $data[$columns['categoryid']];
                                $categoryname = $data[$columns['categoryname']];
                                array_push($categories, [
                                    'icon' => $icon,
                                    'color' => $color,
                                    'id' => $categoryid,
                                    'name' => $categoryname
                                ]);
                            }
                            else if ($currentSection === 'currencies') {
                                $name = $data[$columns['currencyname']];
                                $exchange_rate = $data[$columns['exchange_rate']];
                                if (floatval($exchange_rate) === 1.0) {
                                    $mainCurrencyName = $name;
                                }
                                else {
                                    array_push($currencies, [
                                        'name' => $name,
                                        'exchange_rate' => $exchange_rate
                                    ]);
                                }
                            }
                            else if ($currentSection === 'bills') {
                                $what = $data[$columns['what']];
                                $amount = $data[$columns['amount']];
                                // priority to timestamp
                                if (array_key_exists('timestamp', $columns)) {
                                    $timestamp = $data[$columns['timestamp']];
                                }
                                else if (array_key_exists('date', $columns)) {
                                    $date = $data[$columns['date']];
                                    $timestamp = strtotime($date);
                                }
                                $payer_name = $data[$columns['payer_name']];
                                $payer_weight = $data[$columns['payer_weight']];
                                $owers = $data[$columns['owers']];
                                $payer_active = array_key_exists('payer_active', $columns) ? $data[$columns['payer_active']] : 1;
                                $repeat = array_key_exists('repeat', $columns) ? $data[$columns['repeat']] : 'n';
                                $categoryid = array_key_exists('categoryid', $columns) ? intval($data[$columns['categoryid']]) : null;
                                $paymentmode = array_key_exists('paymentmode', $columns) ? $data[$columns['paymentmode']] : null;
                                $repeatallactive = array_key_exists('repeatallactive', $columns) ? $data[$columns['repeatallactive']] : 0;
                                $repeatuntil = array_key_exists('repeatuntil', $columns) ? $data[$columns['repeatuntil']] : null;
                                $comment = array_key_exists('comment', $columns) ? urldecode($data[$columns['comment']]) : null;

                                // manage members
                                $membersActive[$payer_name] = intval($payer_active);
                                if (is_numeric($payer_weight)) {
                                    $membersWeight[$payer_name] = floatval($payer_weight);
                                }
                                else {
                                    fclose($handle);
                                    return ['message' => $this->trans->t('Malformed CSV, bad payer weight on line %1$s', [$row + 1])];
                                }
                                if (strlen($owers) === 0) {
                                    fclose($handle);
                                    return ['message' => $this->trans->t('Malformed CSV, bad owers on line %1$s', [$row + 1])];
                                }
                                if ($what !== 'deleteMeIfYouWant') {
                                    $owersArray = explode(', ', $owers);
                                    foreach ($owersArray as $ower) {
                                        if (strlen($ower) === 0) {
                                            fclose($handle);
                                            return ['message' => $this->trans->t('Malformed CSV, bad owers on line %1$s', [$row + 1])];
                                        }
                                        if (!array_key_exists($ower, $membersWeight)) {
                                            $membersWeight[$ower] = 1.0;
                                        }
                                    }
                                    if (!is_numeric($amount)) {
                                        fclose($handle);
                                        return ['message' => $this->trans->t('Malformed CSV, bad amount on line %1$s', [$row + 1])];
                                    }
                                    array_push($bills, [
                                        'what' => $what,
                                        'comment' => $comment,
                                        'timestamp' => $timestamp,
                                        'amount' => $amount,
                                        'payer_name' => $payer_name,
                                        'owers' => $owersArray,
                                        'paymentmode' => $paymentmode,
                                        'categoryid' => $categoryid,
                                        'repeat' => $repeat,
                                        'repeatuntil' => $repeatuntil,
                                        'repeatallactive' => $repeatallactive
                                    ]);
                                }
                            }
                        }
                        $row++;
                    }
                    fclose($handle);

                    $memberNameToId = [];

                    // add project
                    $user = $this->userManager->get($userId);
                    $userEmail = $user->getEMailAddress();
                    $projectName = str_replace('.csv', '', $file->getName());
                    $projectid = slugify($projectName);
                    $createDefaultCategories = (count($categories) === 0);
                    $projResult = $this->createProject($projectName, $projectid, '', $userEmail, $userId,
                                                       $createDefaultCategories);
                    if (!is_string($projResult)) {
                        return ['message' => $this->trans->t('Error in project creation, %1$s', [$projResult['message']])];
                    }
                    // set project main currency
                    if ($mainCurrencyName !== null) {
                        $this->editProject($projectid, $projectName, null, null, null, $mainCurrencyName);
                    }
                    // add categories
                    foreach ($categories as $cat) {
                        $insertedCatId = $this->addCategory($projectid, $cat['name'], $cat['icon'], $cat['color']);
                        if (!is_numeric($insertedCatId)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding category %1$s', [$cat['name']])];
                        }
                        $categoryIdConv[$cat['id']] = $insertedCatId;
                    }
                    // add currencies
                    foreach ($currencies as $cur) {
                        $insertedCurId = $this->addCurrency($projectid, $cur['name'], $cur['exchange_rate']);
                        if (!is_numeric($insertedCurId)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding currency %1$s', [$cur['name']])];
                        }
                    }
                    // add members
                    foreach ($membersWeight as $memberName => $weight) {
                        $insertedMember = $this->addMember($projectid, $memberName, $weight, $membersActive[$memberName]);
                        if (!is_array($insertedMember)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding member %1$s', [$memberName])];
                        }
                        $memberNameToId[$memberName] = $insertedMember['id'];
                    }
                    // add bills
                    foreach ($bills as $bill) {
                        // manage category id if this is a custom category
                        $catId = $bill['categoryid'];
                        if (is_numeric($catId) && intval($catId) > 0) {
                            $catId = $categoryIdConv[$catId];
                        }
                        $payerId = $memberNameToId[$bill['payer_name']];
                        $owerIds = [];
                        foreach ($bill['owers'] as $owerName) {
                            array_push($owerIds, $memberNameToId[$owerName]);
                        }
                        $owerIdsStr = implode(',', $owerIds);
                        $addBillResult = $this->addBill($projectid, null, $bill['what'], $payerId,
                                                        $owerIdsStr, $bill['amount'], $bill['repeat'],
                                                        $bill['paymentmode'], $catId, $bill['repeatallactive'],
                                                        $bill['repeatuntil'], $bill['timestamp'], $bill['comment']);
                        if (!is_numeric($addBillResult)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding bill %1$s', [$bill['what']])];
                        }
                    }

                    return $projectid;
                }
                else {
                    return ['message' => $this->trans->t('Access denied')];
                }
            }
            else {
                return ['message' => $this->trans->t('Access denied')];
            }
        }
        else {
            return ['message' => $this->trans->t('Access denied')];
        }
    }

    /**
     * Import a CSV file exported by Splid (https://splid.app/)
     * Manually has to be converted from XLS to CSV first
     */
    public function importSplidCsvProject($path, $userId)
    {
        $cleanPath = str_replace(array('../', '..\\'), '',  $path);
        $userFolder = \OC::$server->getUserFolder($userId);
        if ($userFolder->nodeExists($cleanPath)) {
            $file = $userFolder->get($cleanPath);
            if ($file->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
                if (($handle = $file->fopen('r')) !== false) {
                    $data = null;
                    $members = [];
                    $bills = [];
                    $currencies = [];
                    $mainCurrencyName = null;
                    $row = 0;
                    $getLine = function () {
                        global $data;
                        global $handle;
                        $data = fgetcsv($handle, 1000, ',');
                    };
                    //row 0: [project name] — [export date]
                    $getLine();
                    if (count($data) === 1 and substr_count($data[0], '—') === 1) {
                        $projectName = explode('—', $data[0], 2)[0];
                    } else {
                        return ['message' => $this->trans->t('Malformed CSV, unexpected entries at line 1')];
                    }
                    //row 1: [Created with] Splid (splid.app)
                    $getLine();
                    if (substr_count($data[0], 'Splid (splid.app)') !== 1) {
                        return ['message' => $this->trans->t('Malformed CSV, unexpected entries at line 2')];
                    }
                    // row 2: empty
                    $getLine();
                    if ($data !== [null]) {
                        return ['message' => $this->trans->t('Malformed CSV, unexpected entries at line 3')];
                    }
                    // row 3: column definitions
                    // Titel | Betrag | Währung | Von | Datum | Erstellt am | [Person] | [empty] | [Person] | [empty] ...
                    $getLine();
                    if (count($data) >= 8 and
                        $data[0] === 'Titel' and
                        $data[1] === 'Betrag' and
                        $data[2] === 'Währung' and
                        $data[3] === 'Von' and
                        $data[4] === 'Datum' and
                        $data[5] === 'Erstellt am'
                    ) {
                        for ($i = 6; $i < count($data); $i += 2) {
                            if (!empty($data[$i]) and empty($data[$i+1])) {
                                // add members
                                $members[$i] = $data[$i];
                            } else {
                                return ['message' => $this->trans->t('Malformed CSV, bad column names at line 4')];
                            }
                        }
                    } else {
                        return ['message' => $this->trans->t('Malformed CSV, bad column names at line 4')];
                    }

                    while ($getLine() && $data !== false) {
                        $what = $data[0];
                        $amount = $data[1];
                        $currency = $data[2];
                        if (array_key_exists($currency, $currencies)) {
                            $currencies[$currency] += 1;
                        } else {
                            $currencies[$currency] = 1;
                        }
                        $payer_name = $data[3];
                        // prefer explicit date over creation date
                        if ($data[4] !== '–' and strtotime($data[4]) !== false) {
                            $timestamp = strtotime($data[4]);
                        } else if (strtotime($data[5]) !== false) {
                            $timestamp = strtotime($data[5]);
                        }
                        $owers = $data[$columns['owers']];

                        $owersArray = explode(', ', $owers);
                        foreach ($owersArray as $ower) {
                            if (strlen($ower) === 0) {
                                fclose($handle);
                                return ['message' => $this->trans->t('Malformed CSV, bad owers on line %1$s', [$row + 1])];
                            }
                        }
                        if (!is_numeric($amount)) {
                            fclose($handle);
                            return ['message' => $this->trans->t('Malformed CSV, bad amount on line %1$s', [$row + 1])];
                        }
                        array_push($bills, [
                            'what' => $what,
                            'timestamp' => $timestamp,
                            'amount' => $amount,
                            'payer_name' => $payer_name,
                            'owers' => $owersArray,
                        ]);
                        $row++;
                    }
                    fclose($handle);

                    // guess primary currency by amount of usage
                    $max = 0;
                    foreach ($currencies as $currency => $frequency) {
                        if ($frequency > $max) {
                            $max = $frequency;
                            $mainCurrencyName = $currency;
                        }
                    }

                    $memberNameToId = [];

                    // add project
                    $user = $this->userManager->get($userId);
                    $userEmail = $user->getEMailAddress();
                    $projectid = slugify($projectName);
                    $projResult = $this->createProject(
                        $projectName,
                        $projectid,
                        '',
                        $userEmail,
                        $userId,
                        true
                    );
                    if (!is_string($projResult)) {
                        return ['message' => $this->trans->t('Error in project creation, %1$s', [$projResult['message']])];
                    }
                    // set project main currency
                    if ($mainCurrencyName !== null) {
                        $this->editProject($projectid, $projectName, null, null, null, $mainCurrencyName);
                    }
                    // add currencies
                    foreach ($currencies as $cur => $frequency) {
                        $insertedCurId = $this->addCurrency($projectid, $cur['name'], 1); //TODO: Default exchange rate
                        if (!is_numeric($insertedCurId)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding currency %1$s', [$cur['name']])];
                        }
                    }
                    // add members
                    foreach ($members as $memberName) {
                        $insertedMember = $this->addMember($projectid, $memberName, 1);
                        if (!is_array($insertedMember)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding member %1$s', [$memberName])];
                        }
                        $memberNameToId[$memberName] = $insertedMember['id'];
                    }
                    // add bills
                    foreach ($bills as $bill) {
                        $payerId = $memberNameToId[$bill['payer_name']];
                        $owerIds = [];
                        foreach ($bill['owers'] as $owerName) {
                            array_push($owerIds, $memberNameToId[$owerName]);
                        }
                        $owerIdsStr = implode(',', $owerIds);
                        $addBillResult = $this->addBill(
                            $projectid,
                            null,
                            $bill['what'],
                            $payerId,
                            $owerIdsStr,
                            $bill['amount'],
                            'n',
                            null,
                            null,
                            0,
                            null,
                            $bill['timestamp'],
                            null
                        );
                        if (!is_numeric($addBillResult)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding bill %1$s', [$bill['what']])];
                        }
                    }

                    return $projectid;
                } else {
                    return ['message' => $this->trans->t('Access denied')];
                }
            } else {
                return ['message' => $this->trans->t('Access denied')];
            }
        } else {
            return ['message' => $this->trans->t('Access denied')];
        }
    }

    /**
     * @NoAdminRequired
     */
    public function importSWProject($path, $userId) {
        $cleanPath = str_replace(array('../', '..\\'), '',  $path);
        $userFolder = \OC::$server->getUserFolder();
        if ($userFolder->nodeExists($cleanPath)) {
            $file = $userFolder->get($cleanPath);
            if ($file->getType() === \OCP\Files\FileInfo::TYPE_FILE) {
                if (($handle = $file->fopen('r')) !== false) {
                    $columns = [];
                    $membersWeight = [];
                    $bills = [];
                    $owersArray = [];
                    $categoryNames = [];
                    $row = 0;
                    while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                        $owersList = [];
                        $payer_name = '';
                        // first line : get column order
                        if ($row === 0) {
                            $nbCol = count($data);
                            for ($c=0; $c < $nbCol; $c++) {
                                $columns[$data[$c]] = $c;
                            }
                            if (!array_key_exists('Date', $columns) or
                                !array_key_exists('Description', $columns) or
                                !array_key_exists('Category', $columns) or
                                !array_key_exists('Cost', $columns) or
                                !array_key_exists('Currency', $columns)
                            ) {
                                fclose($handle);
                                return ['message' => $this->trans->t('Malformed CSV, bad column names')];
                            }
                            // manage members
                            $m=0;
                            for ($c=5; $c < $nbCol; $c++){
                                $owersArray[$m] = $data[$c];
                                $m++;
                            }
                            foreach ($owersArray as $ower) {
                                if (strlen($ower) === 0) {
                                    fclose($handle);
                                    return ['message' => $this->trans->t('Malformed CSV, cannot have an empty ower')];
                                }
                                if (!array_key_exists($ower, $membersWeight)) {
                                    $membersWeight[$ower] = 1.0;
                                }
                            }
                        } elseif (!isset($data[$columns['Date']]) || empty($data[$columns['Date']])) {
                            // skip empty lines
                        } elseif (isset($data[$columns['Description']]) && $data[$columns['Description']] === 'Total balance') {
                            // skip the total lines
                        }
                        // normal line : bill
                        else {
                            $what = $data[$columns['Description']];
                            $amount = $data[$columns['Cost']];
                            $date = $data[$columns['Date']];
                            $timestamp = strtotime($date);
                            $l = 0;
                            for ($c=5; $c < $nbCol; $c++){
                                if (max($data[$c], 0) !== 0){
                                    $payer_name = $owersArray[$c-5];
                                }
                                if ($data[$c] === $amount){
                                    continue;
                                } elseif ($data[$c] === -$amount){
                                    $owersList = [];
                                    $owersList[$l++] = $owersArray[$c-5];
                                    break;
                                } else {
                                    $owersList[$l++] = $owersArray[$c-5];
                                };
                            }
                            if (!isset($payer_name) || empty($payer_name)) {
                                return ['message' => $this->trans->t('Malformed CSV, no payer on line %1$s', [$row])];
                            }
                            $payer_weight = 1;

                            if (!is_numeric($amount)) {
                                fclose($handle);
                                return ['message' => $this->trans->t('Malformed CSV, bad amount on line %1$s', [$row])];
                            }
                            $bill = [
                                'what' => $what,
                                'timestamp' => $timestamp,
                                'amount' => $amount,
                                'payer_name' => $payer_name,
                                'owers' => $owersList
                            ];
                            // manage categories
                            if (array_key_exists('Category', $columns) and
                                $data[$columns['Category']] !== null and
                                $data[$columns['Category']] !== '') {
                                $catName = $data[$columns['Category']];
                                if (!in_array($catName, $categoryNames)) {
                                    array_push($categoryNames, $catName);
                                }
                                $bill['category_name'] = $catName;
                            }
                            array_push($bills, $bill);
                        }
                        $row++;
                    }
                    fclose($handle);

                    $memberNameToId = [];

                    // add project
                    $user = $this->userManager->get($userId);
                    $userEmail = $user->getEMailAddress();
                    $projectName = str_replace('.csv', '', $file->getName());
                    $projectid = slugify($projectName);
                    // create default categories only if none are found in the CSV
                    $createDefaultCategories = (count($categoryNames) === 0);
                    $projResult = $this->createProject($projectName, $projectid, '', $userEmail,
                                                       $userId, $createDefaultCategories);
                    if (!is_string($projResult)) {
                        return ['message' => $this->trans->t('Error in project creation, %1$s', [$projResult['message']])];
                    }
                    // add categories
                    $catIdToName = [];
                    foreach ($categoryNames as $catName) {
                        $insertedCatId = $this->addCategory($projectid, $catName, null, '#000000');
                        if (!is_numeric($insertedCatId)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding category %1$s', [$catName])];
                        }
                        $catNameToId[$catName] = $insertedCatId;
                    }
                    // add members
                    foreach ($membersWeight as $memberName => $weight) {
                        $insertedMember = $this->addMember($projectid, $memberName, $weight);
                        if (!is_array($insertedMember)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding member %1$s', [$memberName])];
                        }
                        $memberNameToId[$memberName] = $insertedMember['id'];
                    }
                    // add bills
                    foreach ($bills as $bill) {
                        $payerId = $memberNameToId[$bill['payer_name']];
                        $owerIds = [];
                        foreach ($bill['owers'] as $owerName) {
                            array_push($owerIds, $memberNameToId[$owerName]);
                        }
                        $owerIdsStr = implode(',', $owerIds);
                        // category
                        $catId = null;
                        if (array_key_exists('category_name', $bill) and
                            array_key_exists($bill['category_name'], $catNameToId)) {
                            $catId = $catNameToId[$bill['category_name']];
                        }
                        $addBillResult = $this->addBill($projectid, null, $bill['what'], $payerId, $owerIdsStr, $bill['amount'], 'n',
                                                        null, $catId, 0, null, $bill['timestamp']);
                        if (!is_numeric($addBillResult)) {
                            $this->deleteProject($projectid);
                            return ['message' => $this->trans->t('Error when adding bill %1$s', [$bill['what']])];
                        }
                    }
                    return $projectid;
                }
                else {
                    return ['message' => $this->trans->t('Access denied')];
                }
            }
            else {
                return ['message' => $this->trans->t('Access denied')];
            }
        }
        else {
            return ['message' => $this->trans->t('Access denied')];
        }
    }

    /**
     * auto export
     * triggered by NC cron job
     *
     * export projects
     */
    public function cronAutoExport() {
        date_default_timezone_set('UTC');
        // last day
        $now = new \DateTime();
        $y = $now->format('Y');
        $m = $now->format('m');
        $d = $now->format('d');
        $timestamp = $now->getTimestamp();

        // get begining of today
        $dateMaxDay = new \DateTime($y.'-'.$m.'-'.$d);
        $maxDayTimestamp = $dateMaxDay->getTimestamp();
        $minDayTimestamp = $maxDayTimestamp - 24*60*60;

        $dateMaxDay->modify('-1 day');
        $dailySuffix = '_'.$this->trans->t('daily').'_'.$dateMaxDay->format('Y-m-d');

        // last week
        $now = new \DateTime();
        while (intval($now->format('N')) !== 1) {
            $now->modify('-1 day');
        }
        $y = $now->format('Y');
        $m = $now->format('m');
        $d = $now->format('d');
        $dateWeekMax = new \DateTime($y.'-'.$m.'-'.$d);
        $maxWeekTimestamp = $dateWeekMax->getTimestamp();
        $minWeekTimestamp = $maxWeekTimestamp - 7*24*60*60;
        $dateWeekMin = new \DateTime($y.'-'.$m.'-'.$d);
        $dateWeekMin->modify('-7 day');
        $weeklySuffix = '_'.$this->trans->t('weekly').'_'.$dateWeekMin->format('Y-m-d');

        // last month
        $now = new \DateTime();
        while (intval($now->format('d')) !== 1) {
            $now->modify('-1 day');
        }
        $y = $now->format('Y');
        $m = $now->format('m');
        $d = $now->format('d');
        $dateMonthMax = new \DateTime($y.'-'.$m.'-'.$d);
        $maxMonthTimestamp = $dateMonthMax->getTimestamp();
        $now->modify('-1 day');
        while (intval($now->format('d')) !== 1) {
            $now->modify('-1 day');
        }
        $y = intval($now->format('Y'));
        $m = intval($now->format('m'));
        $d = intval($now->format('d'));
        $dateMonthMin = new \DateTime($y.'-'.$m.'-'.$d);
        $minMonthTimestamp = $dateMonthMin->getTimestamp();
        $monthlySuffix = '_'.$this->trans->t('monthly').'_'.$dateMonthMin->format('Y-m');

        $weekFilterArray = array();
        $weekFilterArray['tsmin'] = $minWeekTimestamp;
        $weekFilterArray['tsmax'] = $maxWeekTimestamp;
        $dayFilterArray = array();
        $dayFilterArray['tsmin'] = $minDayTimestamp;
        $dayFilterArray['tsmax'] = $maxDayTimestamp;
        $monthFilterArray = array();
        $monthFilterArray['tsmin'] = $minMonthTimestamp;
        $monthFilterArray['tsmax'] = $maxMonthTimestamp;

        $qb = $this->dbconnection->getQueryBuilder();

        foreach ($this->userManager->search('') as $u) {
            $uid = $u->getUID();
            $outPath = $this->config->getUserValue($uid, 'cospend', 'outputDirectory', '/Cospend');

            $qb->select('p.id', 'p.name', 'p.autoexport')
            ->from('cospend_projects', 'p')
            ->where(
                $qb->expr()->eq('userid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->neq('p.autoexport', $qb->createNamedParameter('n', IQueryBuilder::PARAM_STR))
            );
            $req = $qb->execute();

            $dbProjectId = null;
            $dbPassword = null;
            while ($row = $req->fetch()) {
                $dbProjectId = $row['id'];
                $dbName  = $row['name'];
                $autoexport = $row['autoexport'];

                $suffix = $dailySuffix;
                if ($autoexport === 'w') {
                    $suffix = $weeklySuffix;
                }
                else if ($autoexport === 'm') {
                    $suffix = $monthlySuffix;
                }
                // check if file already exists
                $exportName = $dbProjectId.$suffix.'.csv';

                $userFolder = \OC::$server->getUserFolder($uid);
                if (! $userFolder->nodeExists($outPath.'/'.$exportName)) {
                    $this->exportCsvProject($dbProjectId, $exportName, $uid);
                }
            }
            $req->closeCursor();
            $qb = $qb->resetQueryParts();
        }
    }

    private function hexToRgb($color) {
        $color = \str_replace('#', '', $color);
        $split_hex_color = str_split($color, 2);
        $r = hexdec($split_hex_color[0]);
        $g = hexdec($split_hex_color[1]);
        $b = hexdec($split_hex_color[2]);
        return ['r' => $r, 'g' => $g, 'b' => $b];
    }

    public function searchBills($projectId, $term) {
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->select('id', 'what', 'comment', 'amount', 'timestamp',
                    'paymentmode', 'categoryid')
           ->from('cospend_bills', 'b')
           ->where(
               $qb->expr()->eq('b.projectid', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
           )
           ->andWhere(
               $qb->expr()->like('b.what', $qb->createNamedParameter('%'.$term.'%', IQueryBuilder::PARAM_STR))
           );
        $qb->orderBy('timestamp', 'ASC');
        $req = $qb->execute();

        // bills by id
        $bills = [];
        while ($row = $req->fetch()){
            $dbBillId = intval($row['id']);
            $dbAmount = floatval($row['amount']);
            $dbWhat = $row['what'];
            $dbTimestamp = intval($row['timestamp']);
            $dbComment = $row['comment'];
            $dbPaymentMode = $row['paymentmode'];
            $dbCategoryId = intval($row['categoryid']);
            array_push($bills, [
                'id' => $dbBillId,
                'projectId' => $projectId,
                'amount' => $dbAmount,
                'what' => $dbWhat,
                'timestamp' => $dbTimestamp,
                'comment' => $dbComment,
                'paymentmode' => $dbPaymentMode,
                'categoryid' => $dbCategoryId
            ]);
        }
        $req->closeCursor();
        $qb = $qb->resetQueryParts();

        return $bills;
    }

    public function getBillActivity(string $userId, ?int $since): array {
        // get projects
        $projects = $this->getProjects($userId);

        // get bills (7 max)
        $bills = [];
        foreach ($projects as $project) {
            $pid = $project['id'];
            $bl = $this->getBills($pid, null, null, null, null, null, null, $since, 20, true);

            // get members by id
            $membersById = [];
            foreach ($project['members'] as $m) {
                $membersById[$m['id']] = $m;
            }
            // add information
            foreach ($bl as $i => $bill) {
                $payerId = $bill['payer_id'];
                $bl[$i]['payer'] = $membersById[$payerId];
                $bl[$i]['project_id'] = $pid;
                $bl[$i]['project_name'] = $project['name'];
            }

            $bills = array_merge($bills, $bl);
        }

        // sort bills by date
        $a = usort($bills, function($a, $b) {
            $ta = $a['timestamp'];
            $tb = $b['timestamp'];
            return ($ta > $tb) ? -1 : 1;
        });

        // take 7 firsts
        return array_slice($bills, 0, 7);
    }

    private function updateProjectLastChanged(string $projectId, int $timestamp) {
        $qb = $this->dbconnection->getQueryBuilder();
        $qb->update('cospend_projects');
        $qb->set('lastchanged', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT));
        $qb->where(
            $qb->expr()->eq('id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_STR))
        );
        $req = $qb->execute();
        $qb = $qb->resetQueryParts();
    }
}
