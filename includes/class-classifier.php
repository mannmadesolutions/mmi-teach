<?php
namespace MMI_Teach;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sorts a posting into one subject area and one grade band from its title, falling back to
 * the licenses the posting asks for when the title alone is generic ("Teacher", "Classroom Teacher").
 */
final class Classifier {

	public const OTHER = 'Other / General';

	/** Substitute posting kinds; '' means a regular (contracted) teaching job. */
	public const SUB_KINDS = array(
		'daily'     => 'Daily / on-call sub',
		'building'  => 'Building sub',
		'long_term' => 'Long-term sub',
	);

	/**
	 * Subject => title/license patterns, checked in order; the first match wins, so the more
	 * specific areas come first ("Special Education Math" is Special Education, "Language Arts"
	 * isn't Art, "K4-8 Music" is Music). Grade-level buckets (Early Childhood, Elementary,
	 * Middle Grades) come last and catch general classroom posts.
	 */
	private const SUBJECTS = array(
		'Special Education'      => '\bspecial\s*ed|\bsped\b|\bsp\.?\s?ed\b|cross[\s-]*categorical|\bebd?\b|emotional|behavio(u)?ral disab|learning disab|\bsld\b|intellectual disab|cognitive disab|\bautism|\basd\b|\biep\b|deaf|hard of hearing|visual(ly)? impair|blind|orthopedic|significant disab|exceptional ed|resource teacher|inclusion|\bsdc\b',
		'ESL / Bilingual'        => '\besl\b|\bell\b|\besol\b|\btesol\b|english (language )?learner|bilingual|dual language|multilingual|\bmll\b|\bel teacher\b|english as a second',
		'Reading & Literacy'     => 'reading|literacy|\bdyslexia',
		'Math'                   => '\bmath|algebra|geometry|calculus|statistics',
		'CTE / Technology'       => 'technology ed|tech(nology)? ed|tech\. ed|business ed|\bbusiness\b|marketing|family (and|&) consumer|\bfacs\b|agri|engineering|\bcte\b|career and tech|career & tech|computer science|coding|computer|culinary|automotive|welding|manufacturing|woods|construction|robotics|\bmedia production|digital media|\bpltw\b|project lead the way',
		'Science'                => 'science|biology|chemistry|physics|earth sci|environmental|anatomy|\bstem\b|steam',
		'English / Language Arts' => '\benglish\b|\bela\b|language arts|literature|writing|journalism|speech and debate|composition',
		'Social Studies'         => 'social studies|history|civics|government|economics|psychology|sociology|geography|humanities',
		'World Languages'        => 'spanish|french|german|chinese|mandarin|latin\b|world language|foreign language|hmong|arabic|japanese|italian|\basl\b|american sign|russian|hebrew|ojibwe|menominee',
		'Music'                  => 'music|\bband\b|choir|choral|orchestra|strings',
		'Art'                    => '\bart\b|\barts\b|ceramics|visual art|drawing|painting|photography|theat(er|re)|drama|dance',
		'Physical Ed & Health'   => 'physical ed|phy\.?\s?ed|\bp\.?e\.?\b|\bhealth\b|adapted pe|wellness|fitness',
		'Library & Media'        => 'librar|media specialist|\blmc\b|instructional media',
		'Gifted & Talented'      => 'gifted|talented|advanced learn|\bgt\b',
		'Intervention / Title I' => 'interventionist|intervention|title i\b|title 1\b|\bmtss\b|\brti\b|academic support',
		'Early Childhood'        => '\b[34]\s*-?\s*k\b|\b4yo|pre-?\s*k\b|pre-?kindergarten|kindergarten|early childhood|pre-?school|head start|early learning|\bk4\b|\bk5\b|\bk3\b',
		'Elementary'             => 'elementary|primary|grade\s*[1-5]\b|\b[1-5](st|nd|rd|th)\s*grade|first grade|second grade|third grade|fourth grade|fifth grade|multi-?age|\bk\s*-\s*[1-5]\b|classroom teacher|homeroom|montessori|lower school',
		'Middle Grades'          => 'middle|grade\s*[6-8]\b|\b[6-8]th\s*grade|sixth grade|seventh grade|eighth grade|junior high',
	);

	/** License words (WECAN's license names) for when the title gives nothing away. */
	private const LICENSE_SUBJECTS = array(
		'Special Education'      => 'special education|cross categorical|emotional behavioral|learning disab|intellectual disab|deaf|visual|orthopedic|early childhood - special',
		'Early Childhood'        => 'early childhood|kindergarten',
		'ESL / Bilingual'        => 'english as a second|bilingual',
		'Reading & Literacy'     => 'reading',
		'Math'                   => 'mathematics',
		'CTE / Technology'       => 'technology education|business|marketing|family and consumer|agriculture|computer science|engineering',
		'Science'                => 'science|biology|chemistry|physics',
		'English / Language Arts' => 'english|language arts',
		'Social Studies'         => 'social studies|history|economics|psychology|geography|political',
		'World Languages'        => 'spanish|french|german|chinese|latin|japanese|world language|sign language',
		'Music'                  => 'music',
		'Art'                    => '\bart\b|theat',
		'Physical Ed & Health'   => 'physical education|health',
		'Library & Media'        => 'library|media',
		'Elementary'             => 'elementary|regular education|grades 1|kindergarten through grade',
	);

	private const BANDS = array(
		'Early Childhood' => '\b[34]\s*-?\s*k\b|pre-?\s*k|kindergarten|early childhood|pre-?school|early learning|head start',
		'Elementary'      => 'elementary|primary|grade\s*[1-5]\b|\b[1-5](st|nd|rd|th)\s*grade|lower school|intermediate',
		'Middle'          => 'middle|junior high|grade\s*[6-8]\b|\b[6-8]th\s*grade',
		'High School'     => 'high school|\bhigh\b|\bhs\b|secondary|senior high|grades?\s*9|\b9-12\b|upper school',
		'K-12'            => '\bk\s*-\s*12\b|\bk12\b|district-?wide|all grades|itinerant',
	);

	public static function subject( string $title, string $position_type, array $licenses = array() ): string {
		$found = self::first_match( self::SUBJECTS, $title );
		if ( $found ) {
			return $found;
		}
		if ( $licenses ) {
			$found = self::first_match( self::LICENSE_SUBJECTS, implode( ' | ', $licenses ) );
			if ( $found ) {
				return $found;
			}
		}
		return self::OTHER;
	}

	public static function grade_band( string $title, string $location ): string {
		return self::first_match( self::BANDS, $title )
			?: self::first_match( self::BANDS, $location )
			?: 'Unspecified';
	}

	/** Every subject label in display order, for the dashboard's filter list. */
	public static function subjects(): array {
		return array_merge( array_keys( self::SUBJECTS ), array( self::OTHER ) );
	}

	/**
	 * Which kind of substitute job this is, or '' for a regular teaching job. Long-term subs are
	 * often posted as type "Teacher" with the term in the title, so the title counts too.
	 */
	public static function sub_kind( string $title, string $position_type ): string {
		$is_sub = stripos( $position_type, 'substitute' ) !== false || preg_match( '/\bsub(stitute)?s?\b|guest teacher/i', $title );
		if ( ! $is_sub ) {
			return '';
		}
		if ( preg_match( '/long[- ]?term|\bLTS\b|\bLT\b|maternity|parental|\bleave\b|semester|year[- ]long|\b(through|until|thru)\b/i', $title ) ) {
			return 'long_term';
		}
		if ( preg_match( '/building|permanent|dedicated|site[- ]based|full[- ]time sub/i', $title ) ) {
			return 'building';
		}
		return 'daily';
	}

	/**
	 * Every column this plugin works out for a posting: county, subject, grade band, substitute
	 * kind and normalized pay. Pay comes from the board's own pay field, else the detail page.
	 *
	 * @param array $row     Posting with at least title, employer, location, position_type, pay_text.
	 * @param array $details Decoded detail-page data (may be empty).
	 */
	public static function derive( array $row, array $details, Places $places ): array {
		$county = $places->resolve( $row['employer'], $row['location'] );
		$pay    = Pay::parse( (string) ( ( $row['pay_text'] ?? '' ) ?: ( $details['salary'] ?? '' ) ) );
		$kind   = self::sub_kind( $row['title'], $row['position_type'] );
		if ( 'daily' === $kind && 'year' === $pay['unit'] ) {
			$kind = 'building'; // A salaried "district substitute" is a full-time, every-day sub.
		}
		return array(
			'county_code'   => $county['code'],
			'county_method' => $county['method'],
			'subject'       => self::subject( $row['title'], $row['position_type'], $details['licenses'] ?? array() ),
			'grade_band'    => self::grade_band( $row['title'], $row['location'] ),
			'sub_kind'      => $kind,
			'pay_min'       => $pay['min'],
			'pay_max'       => $pay['max'],
			'pay_unit'      => $pay['unit'],
			'pay_basis'     => $pay['basis'],
			'pay_day'       => $pay['day'],
		);
	}

	private static function first_match( array $patterns, string $text ): string {
		foreach ( $patterns as $label => $pattern ) {
			if ( preg_match( '/' . $pattern . '/i', $text ) ) {
				return $label;
			}
		}
		return '';
	}
}
