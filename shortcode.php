<?php

// function sage_roi_category_display_horizontal() {
//     $pages_elements = '<style>span.cat-product-count {background: #fff;padding: 7px 10px 10px 10px;border-radius: 100%;}div#category-filter span.cat-product-count {margin-left: 10px;}div#category-filter.category-filter-horizontal span.cat-product-count {margin-left: 0;position: relative;bottom: 10px;}div#category-filter a.current-shop-page-active {background: #dcdcdc;}div#category-filter.category-filter-horizontal span.cat-product-count {position: absolute;top: 3px;right: 3px;display: block;bottom: unset;}div#category-filter a {position: relative;}div#category-filter a{padding: 10px 15px !important}@media (max-width: 651px){.home-shop-store .woocommerce.columns-4 ul.products li.product:not(.has-variation-options) a.woocommerce-LoopProduct-link {min-width: 100%;} .woocommerce ul.products li.product a.woocommerce-LoopProduct-link img {float: left;max-width: 80px;width: 80px;}.woocommerce ul.products li.product a.woocommerce-LoopProduct-link h4.product_category_title, .woocommerce ul.products li.product a.woocommerce-LoopProduct-link h2.woocommerce-loop-product__title,.woocommerce ul.products li.product a.woocommerce-LoopProduct-link span.price {text-align: left;padding-left: 90px;}}</style><div class="category-filter-section"><div class="category-filter-horizontal" name="category-filter" id="category-filter" onchange="categoryFilter(this)">';

//     $taxonomy     = 'product_cat';
//     $orderby      = 'name';  
//     $show_count   = 0;      // 1 for yes, 0 for no
//     $pad_counts   = 0;      // 1 for yes, 0 for no
//     $hierarchical = 1;      // 1 for yes, 0 for no  
//     $title        = '';  
//     $empty        = 0;

//     $args = array(
//             'taxonomy'     => $taxonomy,
//             'orderby'      => $orderby,
//             'show_count'   => $show_count,
//             'pad_counts'   => $pad_counts,
//             'hierarchical' => $hierarchical,
//             'title_li'     => $title,
//             'hide_empty'   => $empty
//     );
//     $all_categories = get_categories( $args );
//     foreach ($all_categories as $cat) {
//         if($cat->category_parent == 0) {
//             $category_id = $cat->term_id;
//             // var_dump($cat->category_count);    
//             // get the thumbnail id using the queried category term_id
//             $product_thumbnail_id = get_term_meta( $category_id, 'thumbnail_id', true ); 

//             // get the image URL
//             $product_image = wp_get_attachment_url( $product_thumbnail_id ); 
//             if($cat->name != 'Uncategorized'){
//                 if($product_image != ''){
//                     $pages_elements .= '<br /><p value="'. get_term_link($cat->slug, 'product_cat') .'"><a href="'. get_term_link($cat->slug, 'product_cat') .'#product-lists">'."<img src='{$product_image}' alt='' width='130' height='70' />" .'<span class="cat-product-count">'.$cat->category_count.'</span></a></p>';    
//                 } else{
//                     $pages_elements .= '<br /><p value="'. get_term_link($cat->slug, 'product_cat') .'"><a href="'. get_term_link($cat->slug, 'product_cat') .'#product-lists">'. $cat->name .$product_image .'<span class="cat-product-count">'.$cat->category_count.'</span></a></p>';
//                 }            
//             }
            
            
            
//             $args2 = array(
//                     'taxonomy'     => $taxonomy,
//                     'child_of'     => 0,
//                     'parent'       => $category_id,
//                     'orderby'      => $orderby,
//                     'show_count'   => $show_count,
//                     'pad_counts'   => $pad_counts,
//                     'hierarchical' => $hierarchical,
//                     'title_li'     => $title,
//                     'hide_empty'   => $empty
//             );
//             $sub_cats = get_categories( $args2 );
//             if($sub_cats) {
//                 foreach($sub_cats as $sub_category) {
//                     $pages_elements .= $sub_category->name ;
//                 } 
//             }
//         }
//     }
	
// 	return $pages_elements.'</div><script>function categoryFilter(x){window.location.href = x.value;} var doc_current_page = document.querySelectorAll("div#category-filter");for(i = 0; i < doc_current_page.length; i++){var all_a_under = doc_current_page[i].querySelectorAll("a");for(x = 0; x < all_a_under.length; x++){var all_x_path = all_a_under[x].href.toString().includes(window.location.pathname);if(all_x_path){if(window.location.pathname != "/"){all_a_under[x].classList.add("current-shop-page-active");}}}}</script></div>';
// }

// add_shortcode('filter-product-horizontal-v2', 'sage_roi_category_display_horizontal');