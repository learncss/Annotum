<?php

/**
 * @package anno
 * This file is part of the Annotum theme for WordPress
 * Built on the Carrington theme framework <http://carringtontheme.com>
 *
 * Copyright 2008-2011 Crowd Favorite, Ltd. All rights reserved. <http://crowdfavorite.com>
 * Released under the GPL license
 * http://www.opensource.org/licenses/gpl-license.php
 */

class Anno_XML_Download {

	static $instance;

	private function __construct() {
		/* Define what our "action" is that we'll
		listen for in our request handlers */
		$this->action = 'anno_xml_download_action';
	}

	public static function i() {
		if (!isset(self::$instance)) {
			self::$instance = new Anno_XML_Download;
		}
		return self::$instance;
	}

	public function setup_filterable_props() {
		$this->debug = apply_filters(__CLASS__.'_debug', false);
	}

	/**
	 * Let WP know how to handle "pretty" requests for PDFs
	 *
	 * @return void
	 */
	public function setup_permalinks() {
		// Returns an array of post types named article
		$article_post_types = get_post_types(array('name' => 'article'), 'object');

		// Ensure we have what we're looking for
		if (!is_array($article_post_types) || empty($article_post_types)) {
			return;
		}

		// Get the first (and hopefully only) one
		$article_post_type = array_shift($article_post_types);

		global $wp;
		$wp->add_query_var('xml'); // Allows us to use get_query_var('xml') later on

		// Tells WP that a request like /articles/my-article-name/xml/ is valid
		add_rewrite_rule($article_post_type->rewrite['slug'].'/([^/]+)/xml/?$', 'index.php?articles=$matches[1]&xml=true', 'top');

		// Enable preview URLs
		$wp->add_query_var('preview');
		add_rewrite_rule($article_post_type->rewrite['slug'].'/([^/]+)/xml/preview/?$', 'index.php?articles=$matches[1]&xml=true&preview=true', 'top');
	}

	public function add_actions() {
		add_action('init', array($this, 'setup_filterable_props'));

		// Run late to make sure all custom post types are registered
		add_action('init', array($this, 'setup_permalinks'), 20);

		// Run at 'wp' so we can use the cool get_query_var functions
		add_action('wp', array($this, 'request_handler'));
	}

	/**
	 * Request handler for XML.
	 */
	public function request_handler() {
		if (get_query_var('xml')) {
			// Sanitize our article ID
			$id = get_the_ID();

			// If we don't have an Article, get out
			if (empty($id)) {
				wp_die(__('No article found.', 'anno'));
			}

			// If we're not debugging, turn off errors
			if (!$this->debug) {
				$display_errors = ini_get('display_errors');
				ini_set('display_errors', 0);
			}

			// Default Article
			$article = null;

			// If we're published, grab the published article
			if (!is_preview()) {
				$article = get_post($id);
			}
			else if (is_preview() && current_user_can('edit_post', $id)) {
				$article = get_post($id);

				/* Drafts and sometimes pending statuses append a preview_id on the
				end of the preview URL.  While we're building the XML download link
				we do a check for that, and set this query arg if the preview_id is
				present. */
				if (isset($_GET['autosave'])) {
					$article = wp_get_post_autosave($id);
				}
			}

			// Ensure we have an article
			if (empty($article)) {
				wp_die(__('Required article first.', 'anno'));
			}

			// Get our XML ready and stored
			$this->generate_xml($article);

			// Send our headers
			if ( empty( $_GET['screen'] ) || ! $_GET['screen'] ) {
				$this->set_headers( $article );
			}

			// Send the file
			echo $this->xml;
			exit;
		}
	}


	/**
	 * Sets the download headers for what will be delivered
	 *
	 * @param obj $article
	 * @return void
	 */
	private function set_headers($article) {
		// Get the post title, so we can set it as the filename
		$filename = sanitize_title(get_the_title($article->ID));
	    header('Cache-Control: private');
		header('Content-Type:text/xml; charset=utf-8');
		header('Content-Length:' . mb_strlen($this->xml, '8bit'));
		header('Content-Disposition: attachment; filename="'.$filename.'.xml"');
	}


	/**
	 * Builds the entire XML document
	 *
	 * @param obj $article - WP Post type object
	 * @return void
	 */
	private function generate_xml($article) {
		$this->xml = $this->xml_front($article)."\n".$this->xml_body($article)."\n".$this->xml_back($article);
	}


	/**
	 * Generate the Front portion of an article XML
	 *
	 * @param postObject $article Article to generate the XML for.
	 * @return string XML generated
	 */
	private function xml_front($article) {

		// Journal Title
		$journal_title = cfct_get_option('journal_name');
		if (!empty($journal_title)) {
			$journal_title_xml = '<journal-title-group>
					<journal-title>'.esc_html($journal_title).'</journal-title>
				</journal-title-group>';
		}
		else {
			$journal_title_xml = '';
		}

		// Journal ID
		$journal_id = cfct_get_option('journal_id');
		if (!empty($journal_id)) {
			$journal_id_type = cfct_get_option('journal_id_type');
			if (!empty($journal_id_type)) {
				$journal_id_type_xml = ' journal-id-type="'.esc_attr($journal_id_type).'"';
			}
			else {
				$journal_id_type_xml = '';
			}

			$journal_id_xml = '<journal-id'.$journal_id_type_xml.'>'.esc_html($journal_id).'</journal-id>';
		}
		else {
			$journal_id_xml = '';
		}

		// Journal ISSN
		$journal_issn = cfct_get_option('journal_issn');
		if (!empty($journal_issn)) {
			$journal_issn_xml = '<issn pub-type="ppub">'.esc_html($journal_issn).'</issn>';
		}
		else {
			$journal_issn_xml = '';
		}

		// Abstract
		$abstract = $article->post_excerpt;
		if (!empty($abstract)) {
			$abstract_xml = '
			<abstract>'
				.$abstract.
			'</abstract>';
		}
		else {
			$abstract_xml = '';
		}

		// Funding Statement
		$funding = get_post_meta($article->ID, '_anno_funding', true);
		if (!empty($funding)) {
			$funding_xml = '<funding-group>
					<funding-statement>'.esc_html($funding).'</funding-statement>
			</funding-group>';
		}
		else {
			$funding_xml = '';
		}

		// DOI
		$doi = get_post_meta($article->ID, '_anno_journal_doi', true);
		if (!empty($doi)) {
			$doi_xml = '<article-id pub-id-type="doi">'.esc_html($doi).'</article-id>';
		}
		else {
			$doi_xml = '';
		}

		// Article category. Theoretically, there can only be one!
		$cats = wp_get_object_terms($article->ID, 'article_category');
		if (!empty($cats) && is_array($cats)) {
			$category = get_category($cats[0]);
			// Hard coding subject
			$category->name = 'Original Research Article';
			if (!empty($category)) {
				$category_xml = '<article-categories>
				<subj-group subj-group-type="heading">
					<subject>'.esc_html($category->name).'</subject>
				</subj-group>
			</article-categories>';
			}
			else {
				$category_xml = '';
			}
		}
		else {
			$category_xml = '';
		}

		// Article Tags
		$tags = wp_get_object_terms($article->ID, 'article_tag');
		if (!empty($tags) && is_array($tags)) {
			$tag_xml = '<kwd-group kwd-group-type="simple">';
			foreach ($tags as $tag) {
				$tag = get_term($tag, 'article_tag');
				$tag_xml .= '<kwd>'.esc_html($tag->name).'</kwd>';
			}
			$tag_xml .= '
			</kwd-group>';
		}
		else {
			$tag_xml = '';
		}

		// Article title/subtitle
		$subtitle =  get_post_meta($article->ID, '_anno_subtitle', true);
		$title_xml = '<title-group>';
		if (!empty($article->post_title) || !empty($subtitle)) {
			$title_xml = '<title-group>';
			if (!empty($article->post_title)) {
				$title_xml .= '
				<article-title>'.esc_html($article->post_title).'</article-title>';
			}
			else {
				$title_xml .= '
				<article-title />';
			}
			if (!empty($subtitle)) {
				$title_xml .= '
				<subtitle>'.esc_html($subtitle).'</subtitle>';
			}
		}
		$title_xml .= '
			</title-group>';

		// Publisher info
		$pub_name = cfct_get_option('publisher_name');
		$pub_loc = cfct_get_option('publisher_location');
		if (!empty($pub_name) || !empty($pub_loc)) {
			$publisher_xml = '<publisher>';
			if (!empty($pub_name)) {
				$publisher_xml .= '
				<publisher-name>'.esc_html($pub_name).'</publisher-name>';
			}

			if (!empty($pub_loc)) {
				$publisher_xml .= '
				<publisher-loc>'.esc_html($pub_loc).'</publisher-loc>';
			}
			$publisher_xml .= '
					</publisher>';
		}
		else {
			$publisher_xml = '';
		}

		$pub_date_xml = $this->xml_pubdate($article->post_date);

		$journal_volume = get_post_meta($article->ID, '_anno_journal_volume', true);
		$journal_volume_xml = '';
		if (!empty($journal_volume)) {
			$journal_volume_xml = '<volume>'.esc_html($journal_volume).'</volume>';
		}

		$journal_issue = get_post_meta($article->ID, '_anno_journal_issue', true);
		$journal_issue_xml = '';
		if (!empty($journal_issue)) {
			$journal_issue_xml = '<issue>'.esc_html($journal_issue).'</issue>';
		}

		$journal_fpage = get_post_meta($article->ID, '_anno_journal_fpage', true);
		$journal_fpage_xml = '';
		if (!empty($journal_fpage)) {
			$journal_fpage_xml = '<fpage>'.esc_html($journal_fpage).'</fpage>';
		}

		$journal_lpage = get_post_meta($article->ID, '_anno_journal_lpage', true);
		$journal_lpage_xml = '';
		if (!empty($journal_lpage)) {
			$journal_lpage_xml = '<lpage>'.esc_html($journal_lpage).'</lpage>';
		}

		$journal_received = get_post_meta($article->ID, '_anno_journal_received', true);
		$journal_accepted = get_post_meta($article->ID, '_anno_journal_accepted', true);
		$received_date = explode('-', $journal_received);
		$accepted_date = explode('-', $journal_accepted);
		if (!empty($journal_received)) {
			$journal_history_xml = '<history>';
			$journal_history_xml .= '
				<date date-type="received">
					<day>'.$received_date[2].'</day>
					<month>'.$received_date[1].'</month>
					<year>'.$received_date[0].'</year>
				</date>';
			if (!empty($journal_accepted)) {
				$journal_history_xml .= '
				<date date-type="accepted">
					<day>'.$accepted_date[2].'</day>
					<month>'.$accepted_date[1].'</month>
					<year>'.$accepted_date[0].'</year>
				</date>';
			}
            $journal_history_xml .= '
            </history>';
		} else {
			$journal_history_xml = '';
		}

		$journal_abbrev = cfct_get_option('journal_abbr');
		if (!empty($journal_abbrev)) {
			$journal_abbrev_xml = '<abbrev-journal-title abbrev-type="full">'.esc_html($journal_abbrev).'</abbrev-journal-title>';
		} else {
			$journal_abbrev_xml = '';
		}

		$journal_copyright = cfct_get_option('publisher_name');
		if (!empty($journal_copyright)) {
			$journal_copyright_xml = '
			<permissions>
              <copyright-statement>&#x00A9; '.date("Y").' '.$journal_copyright.'</copyright-statement>
              <copyright-year>'.date("Y").'</copyright-year>
              <copyright-holder>'.$journal_copyright.'</copyright-holder>
            </permissions>';
		} else { 
			$journal_copyright_xml = '';
		}

		// Authors
		$authors = get_post_meta($article->ID, '_anno_author_snapshot', true);
		if (!empty($authors) && is_array($authors)) {

			$author_xml = '<contrib-group>';

			// Building array of institutes from all authors
			foreach ($authors as $author) {
				if (!empty($author['affiliation']) || !empty($author['institution'])) {
					$all_institutions[] = explode(" | ", $author['institution']);
				}
			}
			// Flatten array and make sure there are no duplicates
			foreach ($all_institutions as $institution_array)
			{
				foreach($institution_array as $institution_key => $institution_array2)
				{
					$institution_list[] = $institution_array2;
				}
			}
			$institution_list = array_values(array_unique($institution_list));

			foreach ($authors as $author) {
				$author_xml .= '
				<contrib contrib-type="author" corresp="yes">';
				if (
					(isset($author['surname']) && !empty($author['surname'])) ||
					(isset($author['given_names']) && !empty($author['given_names'])) ||
					(isset($author['prefix']) && !empty($author['prefix'])) ||
					(isset($author['suffix']) && !empty($author['suffix']))
					) {
						$author_xml .= '
					<name>';
						if (isset($author['surname']) && !empty($author['surname'])) {
							$author_xml .= '
						<surname>'.esc_html($author['surname']).'</surname>';
						}
						if (isset($author['given_names']) && !empty($author['given_names'])) {
							$author_xml .= '
						<given-names>'.esc_html($author['given_names']).'</given-names>';
						}
						if (isset($author['prefix']) && !empty($author['prefix'])) {
							$author_xml .= '
						<prefix>'.esc_html($author['prefix']).'</prefix>';
						}
						if (isset($author['suffix']) && !empty($author['suffix'])) {
							$author_xml .= '
						<suffix>'.esc_html($author['suffix']).'</suffix>';
						}
						$author_xml .= '
					</name>';
					}

					if (isset($author['degrees']) && !empty($author['degrees'])) {
						$author_xml .= '
						<degrees>'.esc_html($author['degrees']).'</degrees>';
					}

					// Affiliation legacy support
					if (!empty($author['affiliation']) || !empty($author['institution'])) {
						$institutions = explode(" | ", $author['institution']);
						$authors_institutions = array_intersect($institution_list, $institutions);
						foreach ( $authors_institutions as $key => $author_institution ) {
							$author_xml .= '
							<xref ref-type="aff" rid="aff'.($key + 1).'">
								<sup>'.($key + 1).'</sup>
							</xref>';
						}
					}

					if (isset($author['bio']) && !empty($author['bio'])) {
						$author_xml .= '
						<bio><p>'.esc_html($author['bio']).'</p></bio>';
					}

					if (isset($author['link']) && !empty($author['link'])) {
						$author_xml .= '
						<ext-link ext-link-type="uri" xlink:href="'.esc_url($author['link']).'">'.esc_html($author['link']).'</ext-link>';
					}

				$author_xml .= '
				</contrib>';
			}

			if (!empty($author['affiliation']) || !empty($author['institution'])) {
				foreach ( $institution_list as $key => $institution ) {
					$author_xml .= '
					<aff id="aff'.($key + 1).'">
					';
					if (!empty($author['affiliation'])) {
						$author_xml .= esc_html($author['affiliation']);
					}
					if (!empty($author['institution'])) {
						$author_xml .= '<institution><sup>'.($key + 1).'</sup>'.esc_html($institution).'</institution>';
					}
					$author_xml .= '</aff>';
				}
			}

			$author_xml .= '
			</contrib-group>';
		}

		$author_notes = get_post_meta($article->ID, '_anno_author_notes', true);
		if (!empty($author_notes)) {
			$author_notes_xml = '<author-notes>
			'.$author_notes.'
			</author-notes>';
		} else {
			$author_notes_xml = '';
		}

		// Related Articles
		$related_xml = '';
		if(anno_workflow_enabled()){
			$related_articles = annowf_clone_get_ancestors($article->ID);
		}
		if (!empty($related_articles) && is_array($related_articles)) {
			foreach ($related_articles as $related_article_id) {
				$related_article = get_post($related_article_id);
				if (!empty($related_article) && $related_article->post_status == 'publish') {
					$related_xml .= '<related-article id="'.esc_attr('a'.$related_article->ID).'" related-article-type="companion" ext-link-type="uri" xlink:href="'.esc_attr(get_permalink($related_article_id)).'" ';

					$related_doi = get_post_meta($related_article_id, '_anno_doi', true);

					if (!empty($related_doi)) {
						$related_xml .= 'elocation-id="'.esc_attr($related_doi).'" ';
					}

					// Queried for above
					if (!empty($journal_id)) {
						$related_xml .= 'journal_id="'.esc_attr($journal_id).'" ';
					}

					// Queried for above
					if (!empty($journal_id_type)) {
						$related_xml .= 'journal_id_type="'.esc_attr($journal_id_type).'" ';
					}

					$related_xml .= ' />';
				}
			}
		}

		return
'<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE article PUBLIC "-//NLM//DTD Journal Publishing DTD v3.0 20080202//EN" "journalpublishing3.dtd">
<article article-type="research-article" xml:lang="en" xmlns:mml="http://www.w3.org/1998/Math/MathML" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
<?origin annotum?>
	<front>
		<journal-meta>
			'.$journal_id_xml.'
			'.$journal_title_xml.'
			'.$journal_abbrev_xml.'
			'.$journal_issn_xml.'
			'.$publisher_xml.'
		</journal-meta>
		<article-meta>
			'.$doi_xml.'
			'.$category_xml.'
			'.$title_xml.'
			'.$author_xml.'
			'.$author_notes_xml.'
			'.$pub_date_xml.'
			'.$journal_volume_xml.'
			'.$journal_issue_xml.'
			'.$journal_fpage_xml.'
			'.$journal_lpage_xml.'
			'.$journal_history_xml.'
			'.$journal_copyright_xml.'
			'.$abstract_xml.'
			'.$tag_xml.'
			'.$funding_xml.'
			'.$related_xml.'
		</article-meta>
	</front>';
	}

	private function xml_body($article) {
		$body = $article->post_content_filtered;
		return
'	<body>
		'.$body.'
	</body>';
	}

	private function xml_acknoledgements($article) {
		$ack = get_post_meta($article->ID, '_anno_acknowledgements', true);
		$xml = '';
		if (!empty($ack)) {
			$xml =
'		<ack>
			<title>'._x('Acknowledgments', 'xml acknowledgments title', 'anno').'</title>
			<p>'.esc_html($ack).'</p>
		</ack>';
		}

		return $xml;
	}

	private function xml_appendices($article) {
		$appendices = get_post_meta($article->ID, '_anno_appendices', true);
		$xml = '';
		if (!empty($appendices) && is_array($appendices)) {
			$xml =
'			<app-group>';

			foreach ($appendices as $appendix_key => $appendix) {
				if (!empty($appendix)) {
					$xml .='
				<app id="app'.($appendix_key + 1).'">
					<sec><title>'.sprintf(_x('Appendix %s', 'xml appendix title', 'anno'), $appendix_key + 1).'</title></sec>'
					.$appendix.'
				</app>';
				}
			}

			$xml .='
			</app-group>';
		}

		return $xml;
	}

private function xml_back($article) {
	if ( $this->xml_acknoledgements( $article ) || $this->xml_appendices( $article ) || anno_xml_references( $article->ID ) ) {
	$xml_back = '	<back>
		' . $this->xml_acknoledgements( $article ) . '
		' . $this->xml_appendices( $article ) . '
		' . anno_xml_references( $article->ID ) . '
		</back>
		' . $this->xml_responses( $article ) . '
		</article>';
		}
	else {
		$xml_back = $this->xml_responses( $article ).'
	</article>';
	}
	return $xml_back;
}

	private function xml_responses($article) {
		$comments = get_comments(array('post_id' => $article->ID));
		$comment_xml = '';
		if (!empty($comments) && is_array($comments)) {
			foreach ($comments as $comment) {
				$comment_xml .= '
	<response response-type="reply">
		<front-stub>'
			.$this->xml_comment_author($comment)
			.$this->xml_pubdate($comment->comment_date).'
		</front-stub>
		<body>
			<p>'
				.esc_html($comment->comment_content).
'			</p>
		</body>
	</response>';
			}
		}
		return $comment_xml;
	}

	private function xml_comment_author($comment) {
		$author_xml = '<contrib-group>
				<contrib contrib-type="author">';
		if (!empty($comment->user_id)) {
			$user = get_userdata($comment->user_id);
			$author_xml .= '
					<name>';

			if (!empty($user->last_name)) {
				$author_xml .= '
						<surname>'.esc_html($user->last_name).'</surname>';
			}
			else {
				$author_xml .= '
						<surname>'.esc_html($user->display_name).'</surname>';
			}
			if (!empty($user->first_name)) {
				$author_xml .= '
						<given-names>'.esc_html($user->first_name).'</given-names>';
			}

			$prefix = get_user_meta($user->ID, '_anno_prefix', true);
			if (!empty($prefix)) {
				$author_xml .= '
						<prefix>'.esc_html($prefix).'</prefix>';
			}

			$suffix = get_user_meta($user->ID, '_anno_suffix', true);
			if (!empty($suffix)) {
				$author_xml .= '
						<suffix>'.esc_html($suffix).'</suffix>';
			}
			$author_xml .= '
					</name>';

			// @TODO TDB whether or not to include email
			if (!empty($user->user_email)) {
				$author_xml .= '
				<email>'.esc_html($user->user_email).'</email>';
			}

			// Affiliation legacy support
			$affiliation = get_user_meta($user->ID, '_anno_affiliation', true);
			$institution = get_user_meta($user->ID, '_anno_institution', true);
			if (!empty($affiliation) || !empty($institution)) {
				$author_xml .= '
					<aff>';
					if (!empty($affiliation)) {
						$author_xml .= esc_html($affiliation);
					}
					if (!empty($institution)) {
						$author_xml .= '<institution>'.esc_html($institution).'</institution>';
					}
				$author_xml .= '</aff>';
			}

			$bio = $user->user_description;
			if (!empty($bio)) {
				$author_xml .= '
					<bio>'.wpautop(esc_html($bio)).'</bio>';
			}

			$link = $user->user_url;
			if (!empty($link)) {
				$author_xml .= '
					<ext-link ext-link-type="uri" xlink:href="'.esc_url($link).'">'.esc_html($link).'</ext-link>';
			}
		}
		else {
			if (!empty($comment->commment_author)) {
				$author_xml .= '
					<name>
						<surname>'.esc_html($comment->comment_author).'</surname>';
				$link = $comment->comment_author_url;
				if (!empty($link)) {
					$author_xml .= '
						<ext-link ext-link-type="uri" xlink:href="'.esc_url($link).'">'.esc_html($link).'</ext-link>';
				}
				$author_xml .= '
					</name>';
			}
		}
		$author_xml .= '
				</contrib>
			</contrib-group>';

		return $author_xml;
	}

	private function xml_pubdate($pub_date) {
		if (!empty($pub_date)) {
			$pub_date = strtotime($pub_date);
			// Electronic pub date
			$pub_date_xml = '
			<pub-date pub-type="epub">
				<day>'.date('j', $pub_date).'</day>
				<month>'.date('n', $pub_date).'</month>
				<year>'.date('Y', $pub_date).'</year>
			</pub-date>';
			// Print pub date
			$pub_date_xml .= '
			<pub-date pub-type="ppub">
				<day>'.date('j', $pub_date).'</day>
				<month>'.date('n', $pub_date).'</month>
				<year>'.date('Y', $pub_date).'</year>
			</pub-date>';
		}
		else {
			$pub_date_xml = '';
		}

		return $pub_date_xml;
	}

	/**
	 * Generates the XML download URL for a post
	 *
	 * @param int $id
	 * @return string
	 */
	public function get_download_url($id = null) {
		// Default to the global $post
		if (is_null($id)) {
			global $post;
			if (empty($post)) {
				$this->log('There is no global $post in scope.');
				return false;
			}
			$id = $post->ID;
		}

		// Build our download link
		$permalink = get_permalink($id);

		/* Handle pretty and ugly permalinks. Need at least this stripos
		function, b/c initial drafts don't get a pretty permalink till
		published, so even if the setting is pretty permalinks the draft's
		permalink is going to be ugly. */
		if (stripos($permalink, 'post_type=article') || get_option('permalink_structure') == '') {
			// Ugly permalinks
			$link = add_query_arg(array('xml' => 'true'), $permalink);
			if (is_preview()) {
				$link = add_query_arg(array('preview' => 'true'), $link);
				if (isset($_GET['preview_id'])) {
					$link = add_query_arg(array('autosave' => 'true'), $link);
				}
			}
		}
		else {
			// Pretty permalinks
			$link = trailingslashit($permalink).'xml/';
			if (is_preview()) {
				$link = trailingslashit($link).'preview/';
				if (isset($_GET['preview_id'])) {
					$link = add_query_arg(array('autosave' => 'true'), $link);
				}
			}
		}
		return $link;
	}

	/**
	 * Conditionally logs messages to the error log
	 *
	 * @param string $msg
	 * @return void
	 */
	private function log($msg) {
		if ($this->debug) {
			error_log($msg);
		}
	}
}
Anno_XML_Download::i()->add_actions();


/**
 * Get the XML download link for a post
 *
 * @param int $id
 * @return string
 */
function anno_xml_download_url($id = null) {
	return Anno_XML_Download::i()->get_download_url($id);
}

?>
