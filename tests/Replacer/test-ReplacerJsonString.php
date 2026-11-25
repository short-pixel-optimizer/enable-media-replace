<?php
/**
 * Tests for the way EnableMediaReplace\Replacer\Replacer2::replaceContent()
 * (build/shortpixel/replacer2/src/Replacer2.php) handles a JSON string literal: a text wrapped
 * in double quotes, which is either a quotation somebody typed with the
 * quotes (a page builder heading) or a string a plugin stored JSON-encoded
 * (a URL with escaped slashes). Covers isJSON(), isJSONString() and
 * replaceJSONString(), directly and through a replace run.
 *
 * @package Enable_Media_Replace
 */

use EnableMediaReplace\Replacer\Modules\Elementor;
use EnableMediaReplace\Replacer\Replacer2;

/**
 * Quoted texts and JSON string literals in Replacer2::replaceContent().
 */
class ReplacerJsonStringTest extends WP_UnitTestCase {

	const OLD_URL = 'http://example.org/wp-content/uploads/old-image.jpg';
	const NEW_URL = 'http://example.org/wp-content/uploads/new-image.jpg';

	/**
	 * Runs replaceContent() on a fresh replacer with the OLD_URL / NEW_URL
	 * pair.
	 *
	 * @param mixed $content Content to replace in.
	 * @return mixed
	 */
	private function replaceFresh( $content ) {
		return ( new Replacer2( 'test' ) )->replaceContent( $content, array( self::OLD_URL ), array( self::NEW_URL ) );
	}

	/**
	 * Invokes a private method of a fresh replacer.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function callPrivate( $method, array $args ) {
		$reflection = new ReflectionMethod( Replacer2::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( new Replacer2( 'test' ), $args );
	}

	// ---------------------------------------------------------------------
	// Quoted text.
	// ---------------------------------------------------------------------

	/**
	 * A text wrapped in double quotes (a quotation in a page builder
	 * heading) is not a JSON document. Inside JSON it comes back as it was:
	 * quotes, non-ASCII characters and trailing whitespace included.
	 */
	public function test_quoted_text_inside_json_is_unchanged() {
		$title = "\"Caf\u{00e9} \u{2013} gr\u{00f6}\u{00df}er\"\n";
		$json  = wp_json_encode(
			array(
				'title' => $title,
				'src'   => self::OLD_URL,
			)
		);

		$result = json_decode( $this->replaceFresh( $json ) );

		$this->assertSame( self::NEW_URL, $result->src, 'Sentinel: the URL was replaced.' );
		$this->assertSame( $title, $result->title, 'The quoted text is unchanged.' );
	}

	/** A quoted text that carries the URL changes in the URL only. */
	public function test_quoted_text_with_the_url_inside_json_only_changes_the_url() {
		$json = wp_json_encode( array( 'title' => "\"Caf\u{00e9}: " . self::OLD_URL . "\"\n" ) );

		$result = json_decode( $this->replaceFresh( $json ) );

		$this->assertSame( "\"Caf\u{00e9}: " . self::NEW_URL . "\"\n", $result->title );
	}

	/** At the top level a quoted text is replaced like any other string. */
	public function test_quoted_text_is_replaced_as_a_plain_string() {
		$result = $this->replaceFresh( "\"Caf\u{00e9}: " . self::OLD_URL . "\"\n" );

		$this->assertSame( "\"Caf\u{00e9}: " . self::NEW_URL . "\"\n", $result );
	}

	/**
	 * A quoted text keeps its quotes and the whitespace before and after
	 * them; only the URL changes.
	 */
	public function test_quoted_text_is_replaced_and_keeps_its_surrounding_whitespace() {
		$result = $this->replaceFresh( " \n\"Caf\u{00e9}: " . self::OLD_URL . "\"\n\t" );

		$this->assertSame( " \n\"Caf\u{00e9}: " . self::NEW_URL . "\"\n\t", $result );
	}

	/**
	 * A text that only starts with a quote is not a literal: it is replaced
	 * as a plain string, and an escaped-slash URL in it is left alone.
	 */
	public function test_text_starting_with_a_quote_is_replaced_as_a_plain_string() {
		$escaped = str_replace( '/', '\/', self::OLD_URL );
		$text    = '"quoted" then ' . self::OLD_URL . ' and ' . $escaped;
		$this->assertNull( json_decode( $text ), 'Sentinel: not valid JSON.' );

		$this->assertSame( '"quoted" then ' . self::NEW_URL . ' and ' . $escaped, $this->replaceFresh( $text ) );
	}

	/**
	 * A serialized quoted text keeps its serialization layer and a valid
	 * length.
	 */
	public function test_serialized_quoted_text_is_replaced_and_serialized_back() {
		$result = $this->replaceFresh( serialize( '"' . self::OLD_URL . '"' ) );

		$this->assertSame( serialize( '"' . self::NEW_URL . '"' ), $result );
	}

	// ---------------------------------------------------------------------
	// JSON string literal with escapes.
	// ---------------------------------------------------------------------

	/**
	 * A string stored JSON-encoded writes its URL with escaped slashes.
	 * The URL is found in that form and the literal keeps its escaping,
	 * also the escapes of the text that is not replaced.
	 */
	public function test_json_string_literal_with_escaped_slashes_is_replaced_and_keeps_its_escaping() {
		$literal = json_encode( "Caf\u{00e9} " . self::OLD_URL );
		$this->assertSame( '"Caf\u00e9 http:\/\/example.org\/wp-content\/uploads\/old-image.jpg"', $literal, 'Sentinel: slashes and non-ASCII are escaped.' );

		$result = $this->replaceFresh( $literal );

		$this->assertSame( '"Caf\u00e9 http:\/\/example.org\/wp-content\/uploads\/new-image.jpg"', $result );
		$this->assertSame( "Caf\u{00e9} " . self::NEW_URL, json_decode( $result ) );
	}

	/**
	 * The same literal as a value inside a JSON document (a string encoded
	 * twice, as a page builder widget stores a nested setting): the value
	 * stays a JSON string literal and its URL is replaced.
	 */
	public function test_json_string_literal_with_escaped_slashes_nested_in_json_is_replaced() {
		$json = json_encode( array( 'setting' => json_encode( self::OLD_URL ), 'n' => 3 ) );
		$this->assertStringContainsString( 'http:\\\\\/\\\\\/example.org', $json, 'Sentinel: the nested literal is escaped twice.' );

		$result = json_decode( $this->replaceFresh( $json ) );

		$this->assertSame( 3, $result->n );
		$this->assertIsString( $result->setting );
		$this->assertSame( json_encode( self::NEW_URL ), $result->setting, 'Still a literal with escaped slashes' );
		$this->assertSame( self::NEW_URL, json_decode( $result->setting ) );
	}

	/** A literal that writes both forms of the URL has both replaced. */
	public function test_json_string_literal_with_plain_and_escaped_urls_has_both_replaced() {
		$literal = '"' . self::OLD_URL . ' ' . str_replace( '/', '\/', self::OLD_URL ) . '"';

		$result = $this->replaceFresh( $literal );

		$this->assertSame( '"' . self::NEW_URL . ' ' . str_replace( '/', '\/', self::NEW_URL ) . '"', $result );
	}

	/**
	 * Every search and replace pair is applied to a literal with escaped
	 * slashes, each with its own replacement.
	 */
	public function test_json_string_literal_with_escaped_slashes_gets_every_pair_replaced() {
		$old_thumb = 'http://example.org/wp-content/uploads/old-image-150x150.jpg';
		$new_thumb = 'http://example.org/wp-content/uploads/new-image-300x300.jpg';
		$literal   = json_encode( $old_thumb . ' and ' . self::OLD_URL );

		$result = ( new Replacer2( 'test' ) )->replaceContent( $literal, array( self::OLD_URL, $old_thumb ), array( self::NEW_URL, $new_thumb ) );

		$this->assertSame( json_encode( $new_thumb . ' and ' . self::NEW_URL ), $result );
	}

	/**
	 * Escaped quotes and backslashes inside the literal are kept as they
	 * are written.
	 */
	public function test_json_string_literal_keeps_its_inner_escaped_quotes_and_backslashes() {
		$literal = '"say \"hi\" in C:\\\\dir: ' . self::OLD_URL . '"';
		$this->assertSame( 'say "hi" in C:\\dir: ' . self::OLD_URL, json_decode( $literal ), 'Sentinel: a JSON string literal with escapes.' );

		$this->assertSame( '"say \"hi\" in C:\\\\dir: ' . self::NEW_URL . '"', $this->replaceFresh( $literal ) );
	}

	/**
	 * A URL written with other JSON escapes (a non-ASCII file name as
	 * `\u00e9`) is not found in the literal's own text. The decoded text is
	 * replaced and encoded again instead (the `json_flags` setting plus
	 * JSON_SAFE_FLAGS), and the whitespace around the literal is kept.
	 */
	public function test_json_string_literal_with_a_unicode_escaped_url_is_re_encoded() {
		$old     = "http://example.org/wp-content/uploads/caf\u{00e9}.jpg";
		$new     = 'http://example.org/wp-content/uploads/coffee.jpg';
		$literal = "\t " . json_encode( 'see ' . $old ) . "\n";
		$this->assertStringContainsString( 'caf\u00e9.jpg', $literal, 'Sentinel: the file name is escaped.' );

		$result = ( new Replacer2( 'test' ) )->replaceContent( $literal, array( $old ), array( $new ) );

		$this->assertSame( "\t " . '"see ' . $new . '"' . "\n", $result, 'Encoded again with the json_flags setting (plain slashes), whitespace kept' );
	}

	/**
	 * The re-encode of a literal keeps escaped markup characters escaped:
	 * the literal holds an escaped `<img onerror>` (inert text for kses)
	 * next to a URL that is only found after decoding. Post content is
	 * written back without kses, so a literal `<` here would be live markup.
	 */
	public function test_json_string_literal_that_is_re_encoded_keeps_markup_characters_escaped() {
		$old     = "http://example.org/wp-content/uploads/caf\xC3\xA9.jpg";
		$new     = 'http://example.org/wp-content/uploads/coffee.jpg';
		$text    = '<img src=x onerror=alert(1)> & \'quoted\' ' . $old;
		$literal = json_encode( $text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS );
		$this->assertStringNotContainsString( '<', $literal, 'Sentinel: the markup is escaped in the stored literal.' );
		$this->assertStringNotContainsString( 'caf' . "\xC3\xA9", $literal, 'Sentinel: the file name is escaped, so the literal is re-encoded.' );

		$result = ( new Replacer2( 'test' ) )->replaceContent( $literal, array( $old ), array( $new ) );

		$this->assertIsString( $result );
		foreach ( array( '<', '>', '&', "'" ) as $character ) {
			$this->assertStringNotContainsString( $character, $result, 'No literal ' . $character . ' after the replacement' );
		}
		$this->assertSame( str_replace( $old, $new, $text ), json_decode( $result ), 'The same text, with the URL replaced' );
	}

	/**
	 * One escaped-markup payload through every shape replaceContent()
	 * decodes and encodes again: no shape may hand back a literal `<`. A new
	 * shape that is re-encoded belongs in this list.
	 */
	public function test_no_shape_turns_escaped_markup_into_literal_markup() {
		$old      = "http://example.org/wp-content/uploads/caf\xC3\xA9.jpg";
		$new      = 'http://example.org/wp-content/uploads/coffee.jpg';
		$text     = '<img src=x onerror=alert(1)> ' . $old;
		$literal  = json_encode( $text, JSON_HEX_TAG );
		$document = json_encode( array( 'text' => $text ), JSON_HEX_TAG );
		$shapes   = array(
			'quoted literal'                       => $literal,
			'JSON document'                        => $document,
			'JSON document holding a literal'      => json_encode( array( 'text' => $literal ), JSON_HEX_TAG ),
			'array holding a literal'              => array( 'text' => $literal ),
			'array holding a document'             => array( 'text' => $document ),
			'serialized literal'                   => serialize( $literal ),
			'serialized array holding a literal'   => serialize( array( 'text' => $literal ) ),
			'serialized array holding a document'  => serialize( array( 'text' => $document ) ),
			'serialized object holding a literal'  => serialize( (object) array( 'text' => $literal ) ),
		);

		foreach ( $shapes as $name => $input ) {
			$this->assertStringNotContainsString( '<', maybe_serialize( $input ), "Sentinel ($name): the markup is escaped in the input." );

			$result = ( new Replacer2( 'test' ) )->replaceContent( $input, array( $old ), array( $new ) );

			$this->assertIsString( $result, "$name comes back as a string" );
			$this->assertStringContainsString( 'coffee.jpg', $result, "Sentinel ($name): the URL was replaced." );
			$this->assertStringNotContainsString( '<', $result, "$name: no literal markup after the replacement" );
		}
	}

	/** A literal in which nothing is replaced comes back byte for byte. */
	public function test_json_string_literal_without_the_url_is_returned_unchanged() {
		$literal = " \"Caf\\u00e9 \\/ gr\u{00f6}\u{00df}er\" \n";
		$this->assertIsString( json_decode( $literal ), 'Sentinel: a JSON string literal.' );

		$this->assertSame( $literal, $this->replaceFresh( $literal ) );
	}

	/**
	 * The content filter receives a literal as it is stored, quotes and
	 * escaped slashes included, and its result is the text replaced in.
	 */
	public function test_content_filter_receives_a_json_string_literal_as_stored() {
		$seen = array();
		add_filter(
			'emr/replacer/content',
			function ( $content ) use ( &$seen ) {
				$seen[] = $content;
				return $content;
			}
		);
		$literal = json_encode( self::OLD_URL );

		$result = $this->replaceFresh( $literal );

		$this->assertSame( array( $literal ), $seen );
		$this->assertSame( json_encode( self::NEW_URL ), $result );
	}

	// ---------------------------------------------------------------------
	// Detection.
	// ---------------------------------------------------------------------

	/**
	 * isJSON() is true for a string that decodes to a non-empty array or an
	 * object; isJSONString() for a string that decodes to a string.
	 */
	public function test_is_json_and_is_json_string_boundaries() {
		$cases = array(
			// value, isJSON, isJSONString.
			array( '{"a":1}', true, false ),
			array( '[1,2]', true, false ),
			array( '{}', true, false ),
			array( '[]', false, false ),
			array( '"abc"', false, true ),
			array( " \"abc\"\n", false, true ),
			array( '""', false, true ),
			array( '"http:\/\/example.org\/a.jpg"', false, true ),
			array( '"abc', false, false ),
			array( '"a" and "b"', false, false ),
			array( 'abc', false, false ),
			array( '123', false, false ),
			array( 'true', false, false ),
			array( 'null', false, false ),
			array( '', false, false ),
			array( 123, false, false ),
			array( null, false, false ),
			array( array( '"abc"' ), false, false ),
		);

		foreach ( $cases as $case ) {
			$label = wp_json_encode( $case[0] );
			$this->assertSame( $case[1], $this->callPrivate( 'isJSON', array( $case[0] ) ), 'isJSON(' . $label . ')' );
			$this->assertSame( $case[2], $this->callPrivate( 'isJSONString', array( $case[0] ) ), 'isJSONString(' . $label . ')' );
		}
	}

	// ---------------------------------------------------------------------
	// Through a replace run.
	// ---------------------------------------------------------------------

	/**
	 * Elementor data in which widgets keep settings encoded a second time:
	 * a JSON object as a string and a URL as a JSON string literal, both
	 * with escaped slashes, next to an image that holds the URL directly
	 * and a heading typed as a quotation. The run of the Elementor module
	 * finds the row; both nested values stay strings in their own form
	 * with the URL replaced, and the quotation is unchanged.
	 */
	public function test_replace_run_rewrites_json_strings_nested_in_elementor_data() {
		$source = 'http://example.org/wp-content/uploads/2020/01/emr-jsonstring-source.jpg';
		$target = 'http://example.org/wp-content/uploads/2020/01/emr-jsonstring-target.jpg';
		$quote  = "\"Caf\u{00e9} \u{2013} gr\u{00f6}\u{00df}er\"\n";
		add_filter( 'emr/replacer/custom_replace_query', array( Elementor::getInstance(), 'addElementor' ), 10, 4 );
		$carrier = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$json    = wp_json_encode(
			array(
				array(
					'settings' => array(
						'image' => array( 'url' => $source ),
						'group' => array( wp_json_encode( array( 'id' => 405, 'url' => $source ) ) ),
						'link'  => wp_json_encode( $source ),
						'quote' => $quote,
					),
				),
			)
		);
		add_post_meta( $carrier, '_elementor_data', wp_slash( $json ) );
		$this->assertSame( $json, get_post_meta( $carrier, '_elementor_data', true ), 'Sentinel: stored as written.' );

		$replacer = new Replacer2( 'test' );
		$replacer->Setup()->search()->setUrl( $source );
		$replacer->Setup()->search()->setMetaData( array( 'file' => '2020/01/emr-jsonstring-source.jpg', 'width' => 100 ) );
		$replacer->Setup()->replace()->setUrl( $target );
		$replacer->Setup()->replace()->setMetaData( array( 'file' => '2020/01/emr-jsonstring-target.jpg', 'width' => 100 ) );

		$result = $replacer->replace();

		$settings = json_decode( get_post_meta( $carrier, '_elementor_data', true ) )[0]->settings;
		$this->assertSame( 1, $result, 'Only the Elementor run matched the row' );
		$this->assertSame( $target, $settings->image->url, 'Sentinel: the direct URL is replaced.' );
		$this->assertIsString( $settings->group[0] );
		$this->assertSame( 405, json_decode( $settings->group[0] )->id );
		$this->assertSame( $target, json_decode( $settings->group[0] )->url );
		$this->assertSame( wp_json_encode( $target ), $settings->link, 'Still a literal with escaped slashes' );
		$this->assertSame( $quote, $settings->quote, 'The quotation is unchanged' );
	}
}
