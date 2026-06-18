<tr>
    <td style="$InlineStyle" align="$safeAlignment">
        <% if $LinkURL %><a href="$LinkURL.ATT" target="_blank"><% end_if %>
        <% with $ScaledImage %>
            <img src="$AbsoluteURL" alt="$Up.Alt.ATT" width="$Width" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;margin:0 auto;" />
        <% end_with %>
        <% if $LinkURL %></a><% end_if %>
    </td>
</tr>
