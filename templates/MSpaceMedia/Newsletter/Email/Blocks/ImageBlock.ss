<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="<% if $FullWidth %>padding:0;<% else %>$InlineStyle<% end_if %>" align="$safeAlignment">
        <% if $LinkURL %><a href="$LinkURL.ATT" target="_blank"><% end_if %>
        <% with $ScaledImage %>
            <% if $Up.FullWidth %>
                <img src="$AbsoluteURL" alt="$Up.Alt.ATT" width="$Up.Brand.ContentWidth" style="display:block;border:0;outline:none;text-decoration:none;width:100%;max-width:100%;height:auto;" />
            <% else %>
                <img src="$AbsoluteURL" alt="$Up.Alt.ATT" width="$Width" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;<% if $Up.Alignment == center %>margin:0 auto;<% end_if %>" />
            <% end_if %>
        <% end_with %>
        <% if $LinkURL %></a><% end_if %>
    </td>
</tr>
