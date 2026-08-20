<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SNS_Content_Generator {

    public static function generate( $post_id, $platform ) {
        $post        = get_post( $post_id );
        $title       = $post->post_title;
        $excerpt     = self::get_clean_excerpt( $post );
        $url         = get_permalink( $post_id );
        $image_url   = get_the_post_thumbnail_url( $post_id, 'large' );
        $hashtags    = self::get_hashtags( $post_id, $platform );
        $template    = self::get_template( $platform );
        $options     = get_option( 'sns_scheduler_options', [] );

        $custom_prompt = $options[ $platform . '_content_template' ] ?? $template;

        $content = str_replace(
            [ '{title}', '{excerpt}', '{url}', '{hashtags}' ],
            [ $title, $excerpt, $url, $hashtags ],
            $custom_prompt
        );

        if ( $platform === 'twitter' && mb_strlen( $content ) > 270 ) {
            $content = mb_substr( $content, 0, 240 ) . '... ' . $url;
        }

        return [
            'content'   => $content,
            'image_url' => $image_url ?: '',
            'post_url'  => $url,
        ];
    }

    private static function get_clean_excerpt( $post ) {
        if ( $post->post_excerpt ) {
            return wp_strip_all_tags( $post->post_excerpt );
        }
        $text = wp_strip_all_tags( $post->post_content );
        return mb_substr( $text, 0, 150 );
    }

    private static function get_hashtags( $post_id, $platform ) {
        $tags = get_the_tags( $post_id );
        $cats = get_the_category( $post_id );
        $items = [];

        if ( $tags ) {
            foreach ( array_slice( $tags, 0, 3 ) as $tag ) {
                $items[] = '#' . preg_replace( '/\s+/', '', $tag->name );
            }
        }
        if ( $cats ) {
            foreach ( array_slice( $cats, 0, 2 ) as $cat ) {
                $items[] = '#' . preg_replace( '/\s+/', '', $cat->name );
            }
        }

        $limit = ( $platform === 'twitter' ) ? 3 : 5;
        return implode( ' ', array_slice( $items, 0, $limit ) );
    }

    private static function get_template( $platform ) {
        $templates = [
            'twitter'   => "✨ {title}\n\n{excerpt}\n\n{hashtags}",
            'threads'   => "✨ {title}\n\n{excerpt}\n\n{hashtags}\n\n👇 자세한 내용은 프로필 링크에서 확인하세요!",
            'pinterest' => "{title}\n\n{excerpt}\n\n{url}\n\n{hashtags}",
            'facebook'  => "📌 {title}\n\n{excerpt}\n\n더 자세한 내용이 궁금하다면? 아래 링크를 클릭하세요 👇\n{url}\n\n{hashtags}",
        ];
        return $templates[ $platform ] ?? "{title}\n\n{excerpt}\n\n{url}";
    }
}
