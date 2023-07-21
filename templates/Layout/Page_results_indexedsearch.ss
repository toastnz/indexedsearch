<div class="searchResults">
    <h1>$Title</h1>

    <% if $Query %>
        <p class="searchQuery">You searched for "$Query"</p>
    <% end_if %>

    <% if $Results %>
        <p>Found $Results.Count results</p>

        <ul>
            <% loop $Results %>
                <li>
                    <h4>
                        <a href="$Link">
                            $Title
                        </a>
                    </h4>

                    <p>$Summary</p>

                    <a href="$Link">Read more</a>
                </li>
            <% end_loop %>
        </ul>

    <% else %>
        <p class="searchNoResults">Sorry, your search query did not return any results.</p>

    <% end_if %>

    <% if $Results.MoreThanOnePage %>
        <div class="searchPagination">
            <% if $Results.NotFirstPage %>
                <a class="prev" href="$Results.PrevLink">Previous</a>
            <% end_if %>
            <span>
                <% loop $Results.Pages %>
                    <% if $CurrentBool %>
                        $PageNum
                    <% else %>
                        <a href="$Link" class="page-number">$PageNum</a>
                    <% end_if %>
                <% end_loop %>
            </span>
            <% if $Results.NotLastPage %>
                <a class="next" href="$Results.NextLink">Next</a>
            <% end_if %>            
        </div>
        <p class="searchPaginationNote">$Results.CurrentPage of $Results.TotalPages</p>
    <% end_if %>
</div>
